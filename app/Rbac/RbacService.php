<?php

namespace App\Rbac;

use App\Accounts\AccountGuard;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * All RBAC mutations. Every public method:
 *  - re-checks the actor's permission (defense in depth, controllers also check),
 *  - runs in one transaction holding a row lock on the company, so concurrent
 *    changes in the same tenant are serialized (last-administrator and
 *    escalation checks see a consistent state),
 *  - writes an audit entry with actor, target, tenant, previous and new state.
 */
class RbacService
{
    public function __construct(private AuditLogger $audit) {}

    /** Permissions the actor may hand out: inside the explicit delegation boundary, held and assignable. @return list<string> */
    public function grantable(User $actor): array
    {
        return PermissionResolver::delegable($actor);
    }

    /** Re-checked inside the tenant lock: the request-level unique rule alone is check-then-insert and races. */
    private function assertNameFree(int $companyId, string $name, ?int $ignoreId): void
    {
        $q = Role::query()->where('company_id', $companyId)->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw new RbacDenied(422, 'role_name_taken', 'A role with this name already exists.');
        }
    }

    private function need(User $actor, string $permission): void
    {
        if (! PermissionResolver::has($actor, $permission)) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }

    private function lockTenant(int $companyId): void
    {
        Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->first();
        PermissionResolver::flush();
    }

    /**
     * Authorization is decided AFTER the tenant lock, against fresh state. The
     * pre-lock check is only a fast refusal: a permission revoked while this
     * request waited for the lock must be honoured, so the actor row is
     * reloaded (active flag, legacy role, assignments) and every required
     * permission is re-evaluated from the database.
     *
     * @param  list<string>  $permissions
     */
    private function authorizeLocked(User $actor, array $permissions): User
    {
        $this->lockTenant($actor->company_id);
        $fresh = User::withoutGlobalScopes()->whereKey($actor->id)->first();
        if (! $fresh || ! $fresh->is_active || $fresh->company_id !== $actor->company_id) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }
        foreach ($permissions as $p) {
            $this->need($fresh, $p);
        }

        return $fresh;
    }

    /** @param list<string> $codes */
    private function assertWithinBoundary(User $actor, array $codes): void
    {
        $outside = array_values(array_diff($codes, $this->grantable($actor)));
        if ($outside) {
            throw new RbacDenied(403, 'delegation_exceeded', 'You cannot grant or change permissions outside your own authorized scope.', $outside);
        }
    }

    // ───────────────────────── roles ─────────────────────────

    /** @param array{name:string,name_ar?:?string,description?:?string,permissions?:list<string>} $data */
    public function createRole(User $actor, array $data): Role
    {
        $this->need($actor, 'roles.create');
        $permissions = array_values(array_unique($data['permissions'] ?? []));

        return DB::transaction(function () use ($actor, $data, $permissions) {
            $actor = $this->authorizeLocked($actor, $permissions ? ['roles.create', 'permissions.manage'] : ['roles.create']);
            if ($permissions) {
                $this->assertWithinBoundary($actor, $permissions);
            }

            $this->assertNameFree($actor->company_id, $data['name'], null);
            $role = new Role(['name' => trim($data['name']), 'name_ar' => $data['name_ar'] ?? null, 'description' => $data['description'] ?? null]);
            // Bounded internal identifier, independent of the display name (7 + 26 = 33 chars <= roles.key 80).
            $role->key = 'custom_'.Str::lower((string) Str::ulid());
            $role->is_system = false;
            $role->is_protected = false;
            $role->save();
            $this->writePermissions($role, $permissions);

            $this->audit->log('rbac.role.created', $role, null, $this->roleState($role), $actor);
            PermissionResolver::flush();

            return $role;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateRole(User $actor, Role $role, array $data): Role
    {
        $this->need($actor, 'roles.update');

        return DB::transaction(function () use ($actor, $role, $data) {
            $actor = $this->authorizeLocked($actor, array_key_exists('permissions', $data) ? ['roles.update', 'permissions.manage'] : ['roles.update']);
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->is_protected || $role->is_system) {
                throw new RbacDenied(403, 'role_protected', 'This role is protected and cannot be modified.');
            }
            $before = $this->roleState($role);

            if (isset($data['name'])) {
                $this->assertNameFree($role->company_id, $data['name'], $role->id);
            }
            $role->fill(array_intersect_key($data, array_flip(['name', 'name_ar', 'description'])));

            if (array_key_exists('permissions', $data)) {
                $new = array_values(array_unique($data['permissions']));
                $old = $role->permissionCodes();
                $changed = array_values(array_merge(array_diff($new, $old), array_diff($old, $new)));
                if ($changed) {
                    $this->assertWithinBoundary($actor, $changed);
                    $added = array_diff($new, $old);
                    // Indirect self-escalation: the actor holds this role and is adding to it.
                    $holdsRole = DB::table('user_roles')->where('role_id', $role->id)->where('user_id', $actor->id)->exists();
                    if ($added && $holdsRole) {
                        throw new RbacDenied(403, 'self_escalation', 'You cannot add permissions to a role that is assigned to you.', array_values($added));
                    }
                    $this->writePermissions($role, $new);
                }
            }
            $role->save();
            $this->assertAdministratorRemains($role->company_id);

            $this->audit->log('rbac.role.updated', $role, $before, $this->roleState($role), $actor);
            PermissionResolver::flush();

            return $role;
        });
    }

    public function deleteRole(User $actor, Role $role): void
    {
        $this->need($actor, 'roles.delete');

        DB::transaction(function () use ($actor, $role) {
            $actor = $this->authorizeLocked($actor, ['roles.delete']);
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->is_protected || $role->is_system) {
                throw new RbacDenied(403, 'role_protected', 'This role is protected and cannot be deleted.');
            }
            if (DB::table('user_roles')->where('role_id', $role->id)->exists()) {
                throw new RbacDenied(409, 'role_in_use', 'Remove this role from all users before deleting it.');
            }
            $before = $this->roleState($role);
            $this->audit->log('rbac.role.deleted', $role, $before, null, $actor);
            $role->delete();
            PermissionResolver::flush();
        });
    }

    // ───────────────────────── assignments ─────────────────────────

    /**
     * Replace a user's roles. Applies to single and bulk changes alike.
     *
     * @param  list<int>  $roleIds
     */
    public function syncUserRoles(User $actor, User $target, array $roleIds): User
    {
        $this->need($actor, 'roles.assign');

        return DB::transaction(function () use ($actor, $target, $roleIds) {
            $actor = $this->authorizeLocked($actor, ['roles.assign']);

            if ($target->company_id !== $actor->company_id || $target->isSuperAdmin()) {
                throw new RbacDenied(404, 'not_found', 'Not found.');
            }
            if ($target->id === $actor->id) {
                throw new RbacDenied(403, 'self_assignment', 'You cannot change your own role assignments.');
            }

            $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
            $roles = Role::query()->whereIn('id', $roleIds)->get();
            if ($roles->count() !== count($roleIds)) {
                throw new RbacDenied(422, 'invalid_role', 'One or more roles are invalid.');
            }

            $currentIds = DB::table('user_roles')->where('user_id', $target->id)->pluck('role_id')->map(fn ($i) => (int) $i)->all();
            $add = array_values(array_diff($roleIds, $currentIds));
            $remove = array_values(array_diff($currentIds, $roleIds));

            // Protected administrators: the System Super Admin role is provisioned, transferred and
            // revoked only by the trusted operator commands, never through the tenant API, and the
            // account that holds it cannot be changed here (not by HR, not by anyone).
            $protectedIds = Role::query()->where('company_id', $actor->company_id)->where('key', Registry::SYSTEM_ADMIN)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (array_intersect($protectedIds, array_merge($add, $remove, $currentIds))) {
                throw new RbacDenied(403, 'protected_account', 'This protected administrator role can only be managed through the trusted operator procedure.');
            }

            // Phase 3.3: HR Administrator assignment and revocation belongs to the System Super Admin. The HR
            // system role carries protected operational permissions (company settings, audit, ...) that are
            // never delegable, so it is gated on the actor's protected role instead of the delegation list.
            $hrRoleIds = Role::query()->where('company_id', $actor->company_id)->where('key', 'hr_administrator')->where('is_system', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
            foreach (array_merge($add, $remove) as $rid) {
                if (in_array($rid, $hrRoleIds, true)) {
                    if (! AccountGuard::isSystemAdmin($actor)) {
                        throw new RbacDenied(403, 'delegation_exceeded', 'Only the System Super Admin can assign or revoke the HR Administrator role.', $this->permissionsOfRole($rid));
                    }

                    continue;
                }
                // You cannot grant, or strip someone of, a role that carries more than you may delegate.
                $this->assertWithinBoundary($actor, $this->permissionsOfRole($rid));
            }

            $before = $this->userState($target);
            if ($remove) {
                DB::table('user_roles')->where('user_id', $target->id)->whereIn('role_id', $remove)->delete();
            }
            foreach ($add as $rid) {
                DB::table('user_roles')->insert([
                    'company_id' => $actor->company_id,
                    'user_id' => $target->id,
                    'role_id' => $rid,
                    'assigned_by_user_id' => $actor->id,
                    'created_at' => now(),
                ]);
            }
            PermissionResolver::flush();
            $this->assertAdministratorRemains($actor->company_id);

            $this->audit->log('rbac.user_roles.updated', $target, $before, $this->userState($target), $actor);
            PermissionResolver::flush();

            return $target;
        });
    }

    // ───────────────────────── system roles / bootstrap ─────────────────────────

    /** Create the protected system roles for a tenant if missing. Never elevates anyone. */
    public function ensureSystemRoles(Company $company, string $key = 'hr_administrator'): Role
    {
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', $key)->first();
        if ($role) {
            return $role;
        }
        $def = Registry::systemRole($key);
        DB::table('roles')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'key' => $key, 'name' => $def['name'], 'name_ar' => $def['name_ar'],
            'description' => $def['description'], 'is_system' => true, 'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', $key)->firstOrFail();
        $this->writePermissions($role, $def['permissions']);
        foreach ($def['delegates'] as $code) {
            DB::table('role_delegations')->insert(['role_id' => $role->id, 'permission' => $code, 'created_at' => now()]);
        }

        return $role;
    }

    /**
     * Trusted bootstrap: give one existing active account in a tenant the HR
     * Administrator role. Used only by the artisan command, for existing
     * and new tenants alike. Not reachable through any HTTP endpoint.
     */
    public function bootstrapHr(Company $company, User $user, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($company, $user, $actor, $reason) {
            $this->lockTenant($company->id);
            if ($user->company_id !== $company->id || $user->isSuperAdmin() || ! $user->is_active) {
                throw new RbacDenied(422, 'invalid_target', 'The account must be an active, non platform account of this company.');
            }
            $role = $this->ensureSystemRoles($company);
            $exists = DB::table('user_roles')->where('user_id', $user->id)->where('role_id', $role->id)->exists();
            if (! $exists) {
                $before = $this->userState($user);
                DB::table('user_roles')->insert([
                    'company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id,
                    'assigned_by_user_id' => $actor?->id, 'created_at' => now(),
                ]);
                PermissionResolver::flush();
                $this->audit->log('rbac.bootstrap_hr', $user, $before, $this->userState($user) + ($reason ? ['reason' => $reason] : []), $actor);
            }
            PermissionResolver::flush();
        });
    }

    // ───────────────────────── trusted operator procedures (artisan only, no HTTP) ─────────────────────────

    private function assertReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 15 || mb_strlen($reason) > 500) {
            throw new RbacDenied(422, 'reason_required', 'A meaningful reason of 15 to 500 characters is required.');
        }

        return $reason;
    }

    private function assertEligible(Company $company, User $user): void
    {
        if ($user->company_id !== $company->id || $user->isSuperAdmin() || ! $user->is_active || $user->trashed()) {
            throw new RbacDenied(422, 'invalid_target', 'The account must be an active, non platform account of this company.');
        }
    }

    private function auditOrFail(string $action, Model $model, ?array $before, ?array $after, ?User $actor): void
    {
        if ($this->audit->log($action, $model, $before, $after, $actor) === null) {
            throw new RuntimeException('Audit record could not be written; the change was rolled back.');
        }
    }

    /** User ids currently holding the singleton role (active or not). @return list<int> */
    private function systemAdminHolders(int $companyId): array
    {
        return DB::table('user_roles')->where('company_id', $companyId)->where('exclusive_key', Registry::SYSTEM_ADMIN)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    private function insertSystemAdmin(Company $company, User $user, Role $role): void
    {
        DB::table('user_roles')->insert([
            'company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id,
            'assigned_by_user_id' => null, 'exclusive_key' => Registry::SYSTEM_ADMIN, 'created_at' => now(),
        ]);
    }

    /**
     * Provision the single System Super Admin of a tenant. Idempotent for the same account.
     * Refuses when a different account holds the role (use the transfer procedure).
     *
     * @return string outcome: created|already|dry_run
     */
    public function bootstrapSystemAdmin(Company $company, User $user, string $reason, bool $dryRun = false): string
    {
        $reason = $this->assertReason($reason);

        return DB::transaction(function () use ($company, $user, $reason, $dryRun) {
            $this->lockTenant($company->id);
            $user = User::withoutGlobalScopes()->whereKey($user->id)->firstOrFail();
            $this->assertEligible($company, $user);
            $holders = $this->systemAdminHolders($company->id);
            if (in_array($user->id, $holders, true)) {
                return 'already';
            }
            if ($holders) {
                throw new RbacDenied(409, 'system_admin_exists', 'This company already has a System Super Admin. Use the ownership transfer procedure.');
            }
            if ($dryRun) {
                return 'dry_run';
            }
            $role = $this->ensureSystemRoles($company, Registry::SYSTEM_ADMIN);
            $before = $this->userState($user);
            $this->insertSystemAdmin($company, $user, $role);
            PermissionResolver::flush();
            $this->auditOrFail('rbac.bootstrap_system_admin', $user, $before, $this->userState($user) + ['reason' => $reason, 'procedure' => 'operator'], null);

            return 'created';
        });
    }

    /**
     * Atomic ownership transfer: the previous holder (active or not) loses the role and the
     * replacement receives it in one transaction. Never leaves the tenant without a
     * permission administrator.
     *
     * @return string outcome: transferred|already|dry_run
     */
    public function transferSystemAdmin(Company $company, User $from, User $to, string $reason, bool $dryRun = false): string
    {
        $reason = $this->assertReason($reason);
        if ($from->id === $to->id) {
            throw new RbacDenied(422, 'invalid_target', 'The previous and the replacement administrator must be different accounts.');
        }

        return DB::transaction(function () use ($company, $from, $to, $reason, $dryRun) {
            $this->lockTenant($company->id);
            $from = User::withoutGlobalScopes()->whereKey($from->id)->firstOrFail();
            $to = User::withoutGlobalScopes()->whereKey($to->id)->firstOrFail();
            if ($from->company_id !== $company->id || $from->isSuperAdmin()) {
                throw new RbacDenied(422, 'invalid_target', 'The previous administrator must be an account of this company.');
            }
            $this->assertEligible($company, $to);
            $holders = $this->systemAdminHolders($company->id);
            if ($holders === [$to->id]) {
                return 'already';
            }
            if ($holders !== [$from->id]) {
                throw new RbacDenied(409, 'not_current_holder', 'The named previous administrator is not the current System Super Admin of this company.');
            }
            if ($dryRun) {
                return 'dry_run';
            }
            $role = $this->ensureSystemRoles($company, Registry::SYSTEM_ADMIN);
            $beforeFrom = $this->userState($from);
            $beforeTo = $this->userState($to);
            DB::table('user_roles')->where('company_id', $company->id)->where('exclusive_key', Registry::SYSTEM_ADMIN)->delete();
            $this->insertSystemAdmin($company, $to, $role);
            PermissionResolver::flush();
            $this->assertAdministratorRemains($company->id);
            $this->auditOrFail('rbac.transfer_system_admin.revoked', $from, $beforeFrom, $this->userState($from) + ['reason' => $reason, 'replaced_by' => $to->id, 'procedure' => 'operator'], null);
            $this->auditOrFail('rbac.transfer_system_admin.granted', $to, $beforeTo, $this->userState($to) + ['reason' => $reason, 'replaced' => $from->id, 'procedure' => 'operator'], null);

            return 'transferred';
        });
    }

    /**
     * Remove the System Super Admin role without a replacement. Refused when that would leave
     * the tenant without any permission administrator.
     *
     * @return string outcome: revoked|none|dry_run
     */
    public function revokeSystemAdmin(Company $company, User $user, string $reason, bool $dryRun = false): string
    {
        $reason = $this->assertReason($reason);

        return DB::transaction(function () use ($company, $user, $reason, $dryRun) {
            $this->lockTenant($company->id);
            $user = User::withoutGlobalScopes()->whereKey($user->id)->firstOrFail();
            if ($user->company_id !== $company->id || $user->isSuperAdmin()) {
                throw new RbacDenied(422, 'invalid_target', 'The account must belong to this company.');
            }
            if (! in_array($user->id, $this->systemAdminHolders($company->id), true)) {
                return 'none';
            }
            if ($dryRun) {
                $this->simulateRemovalKeepsAdministrator($company->id, $user->id);

                return 'dry_run';
            }
            $before = $this->userState($user);
            DB::table('user_roles')->where('company_id', $company->id)->where('user_id', $user->id)->where('exclusive_key', Registry::SYSTEM_ADMIN)->delete();
            PermissionResolver::flush();
            $this->assertAdministratorRemains($company->id);
            $this->auditOrFail('rbac.revoke_system_admin', $user, $before, $this->userState($user) + ['reason' => $reason, 'procedure' => 'operator'], null);

            return 'revoked';
        });
    }

    /**
     * Trusted revocation of the HR Administrator role from one account.
     *
     * @return string outcome: revoked|none|dry_run
     */
    public function revokeHr(Company $company, User $user, string $reason, bool $dryRun = false): string
    {
        $reason = $this->assertReason($reason);

        return DB::transaction(function () use ($company, $user, $reason, $dryRun) {
            $this->lockTenant($company->id);
            $user = User::withoutGlobalScopes()->whereKey($user->id)->firstOrFail();
            if ($user->company_id !== $company->id || $user->isSuperAdmin()) {
                throw new RbacDenied(422, 'invalid_target', 'The account must belong to this company.');
            }
            $hr = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'hr_administrator')->first();
            if (! $hr || ! DB::table('user_roles')->where('user_id', $user->id)->where('role_id', $hr->id)->exists()) {
                return 'none';
            }
            if ($dryRun) {
                $this->simulateRemovalKeepsAdministrator($company->id, $user->id, $hr->id);

                return 'dry_run';
            }
            $before = $this->userState($user);
            DB::table('user_roles')->where('user_id', $user->id)->where('role_id', $hr->id)->delete();
            PermissionResolver::flush();
            $this->assertAdministratorRemains($company->id);
            $this->auditOrFail('rbac.revoke_hr', $user, $before, $this->userState($user) + ['reason' => $reason, 'procedure' => 'operator'], null);

            return 'revoked';
        });
    }

    /** Dry run helper: performs the removal inside a savepoint, checks the invariant, and rolls it back. */
    private function simulateRemovalKeepsAdministrator(int $companyId, int $userId, ?int $roleId = null): void
    {
        DB::beginTransaction();
        try {
            $q = DB::table('user_roles')->where('company_id', $companyId)->where('user_id', $userId);
            $roleId ? $q->where('role_id', $roleId)->delete() : $q->where('exclusive_key', Registry::SYSTEM_ADMIN)->delete();
            PermissionResolver::flush();
            $this->assertAdministratorRemains($companyId);
        } finally {
            DB::rollBack();
            PermissionResolver::flush();
        }
    }

    // ───────────────────────── internals ─────────────────────────

    /** @return list<string> */
    private function permissionsOfRole(int $roleId): array
    {
        return DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
    }

    /** @param list<string> $codes */
    private function writePermissions(Role $role, array $codes): void
    {
        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach (array_values(array_unique($codes)) as $code) {
            DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission' => $code, 'created_at' => now()]);
        }
    }

    /** @return array<string,mixed> */
    private function roleState(Role $role): array
    {
        return ['key' => $role->key, 'name' => $role->name, 'is_system' => $role->is_system, 'permissions' => $role->permissionCodes()];
    }

    /** @return array<string,mixed> */
    private function userState(User $user): array
    {
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $user->id)->orderBy('r.key')->pluck('r.key')->all();

        return ['user_id' => $user->id, 'roles' => $roles];
    }

    /** At least one active, non-platform user in the tenant must keep every permission-administrator permission. */
    public function assertAdministratorRemains(int $companyId): void
    {
        $need = config('rbac.permission_administrator');
        $rows = DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.company_id', $companyId)
            ->where('u.is_active', true)
            ->where(fn ($q) => $q->where('u.is_super_admin', false)->orWhereNull('u.is_super_admin'))
            ->whereNull('u.deleted_at')
            ->whereIn('rp.permission', $need)
            ->select('ur.user_id', 'rp.permission')
            ->distinct()
            ->get()
            ->groupBy('user_id');
        $ok = $rows->contains(fn ($perms) => count(array_diff($need, $perms->pluck('permission')->all())) === 0);
        if (! $ok) {
            throw new RbacDenied(409, 'last_administrator', 'This change would leave the company without a permission administrator.');
        }
    }
}
