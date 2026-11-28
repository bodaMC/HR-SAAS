<?php

namespace App\Accounts;

use App\Enums\EmploymentStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Organization\EmployeeEligibility;
use App\Organization\OrgService;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rbac\RbacService;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditVisibility;
use App\Support\PasswordPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Phase 3.3 employee-account lifecycle. Every mutation runs in ONE transaction that:
 *   1. locks the tenant (the same lock RBAC mutations take, so privilege changes serialise with these),
 *   2. re-reads and re-authorises the actor (fresh permissions, still active, same tenant),
 *   3. locks and re-validates the employee and the target account, and runs the AccountGuard,
 *   4. writes the audit record (failure rolls everything back).
 * Credentials are never audited or logged. A generated temporary password is returned once.
 */
class AccountService
{
    public function __construct(private AuditLogger $audit, private RbacService $rbac) {}

    // ───────────────────────── plumbing ─────────────────────────

    private function lockTenant(int $companyId): void
    {
        Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->first();
        PermissionResolver::flush();
    }

    private function freshActor(User $actor, string $permission): User
    {
        $fresh = User::withoutGlobalScopes()->whereKey($actor->id)->first();
        PermissionResolver::flush();
        if (! $fresh || ! $fresh->is_active || $fresh->trashed() || $fresh->isSuperAdmin() || $fresh->company_id !== $actor->company_id
            || ! PermissionResolver::has($fresh, $permission)) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }

        return $fresh;
    }

    private function begin(User $actor, string $permission): User
    {
        $this->lockTenant($actor->company_id);

        return $this->freshActor($actor, $permission);
    }

    private function employee(User $actor, int $id, bool $withTrashed = false): Employee
    {
        $q = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($id);
        if ($withTrashed) {
            $q->withTrashed();
        }
        $employee = $q->lockForUpdate()->first();
        if (! $employee) {
            throw new RbacDenied(404, 'not_found', 'Not found.');
        }

        return $employee;
    }

    private function linkedUser(Employee $employee): ?User
    {
        if ($employee->user_id === null) {
            return null;
        }

        return User::withoutGlobalScopes()->withTrashed()->where('company_id', $employee->company_id)->whereKey($employee->user_id)->lockForUpdate()->first();
    }

    private function reason(?string $reason, bool $required = true): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '' && ! $required) {
            return null;
        }
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => [__('accounts.reason_required')]]);
        }

        return $reason;
    }

    private function confirm(Employee $employee, ?string $uuid): void
    {
        if (! is_string($uuid) || ! hash_equals($employee->uuid, $uuid)) {
            throw ValidationException::withMessages(['confirm_employee_uuid' => [__('accounts.confirmation_mismatch')]]);
        }
    }

    private function guard(User $actor, User $target): void
    {
        $code = AccountGuard::denial($actor, $target);
        if ($code !== null) {
            throw new RbacDenied(403, $code, __('accounts.protected_denied'));
        }
    }

    private function auditOrFail(string $action, $model, ?array $before, ?array $after, User $actor, ?string $reason = null, ?string $visibility = null): void
    {
        if ($this->audit->log($action, $model, $before, $after, $actor, $reason, $visibility) === null) {
            throw new RuntimeException('Audit record could not be written; the change was rolled back.');
        }
    }

    /** Audit visibility: events about privileged accounts are restricted to full auditors. */
    private function vis(string $action, User $target): string
    {
        return AuditAccountVisibility::for($action, $target);
    }

    private function revokeTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /** Record a refused protected-account attempt (own transaction, after the mutation rolled back). */
    private function recordDenied(User $actor, ?int $employeeId, ?int $targetUserId, string $operation, RbacDenied $e): void
    {
        if (! in_array($e->errorCode, ['protected_platform_account', 'protected_role_holder', 'protected_classification', 'protected_privileges', 'exceeds_actor_scope', 'self_target'], true)) {
            return;
        }
        try {
            $this->audit->log('account.protected_attempt', $actor, null, [
                'operation' => $operation, 'employee_id' => $employeeId, 'target_user_id' => $targetUserId, 'denial' => $e->errorCode,
            ], $actor, null, AuditVisibility::RESTRICTED);
        } catch (\Throwable) {
            // The refusal itself already happened; never let audit trouble change the response.
        }
    }

    /** @template T @param  callable(): T  $fn @return T */
    private function run(User $actor, string $operation, ?int $employeeId, callable $fn)
    {
        try {
            return DB::transaction($fn);
        } catch (RbacDenied $e) {
            $this->recordDenied($actor, $employeeId, $this->peekTarget($actor, $employeeId), $operation, $e);
            throw $e;
        }
    }

    private function peekTarget(User $actor, ?int $employeeId): ?int
    {
        return $employeeId ? Employee::withoutGlobalScopes()->withTrashed()->where('company_id', $actor->company_id)->whereKey($employeeId)->value('user_id') : null;
    }

    public function issueToken(User $user, string $device = 'orbit-auth-token'): string
    {
        $expiration = config('sanctum.expiration');

        return $user->createToken($device, ['*'], $expiration ? now()->addMinutes((int) $expiration) : null)->plainTextToken;
    }

    // ───────────────────────── account state (read model) ─────────────────────────

    /** @return array<string, mixed> */
    public function state(User $actor, Employee $employee): array
    {
        $user = $employee->user_id ? User::withoutGlobalScopes()->withTrashed()->where('company_id', $employee->company_id)->whereKey($employee->user_id)->first() : null;
        $has = fn (string $p) => PermissionResolver::has($actor, $p);
        $terminated = $employee->employment_status === EmploymentStatus::Terminated || $employee->trashed();
        $base = [
            'employee_id' => $employee->id,
            'employee_uuid' => $employee->uuid,
            'has_account' => $user !== null,
            'is_self' => $employee->user_id !== null && $employee->user_id === $actor->id,
        ];
        if (! $user) {
            return $base + ['state' => 'no_login', 'actions' => [
                'create' => $has('accounts.create') && ! $terminated,
                'link' => $has('accounts.link') && ! $terminated,
                'reset_password' => false, 'deactivate' => false, 'reactivate' => false, 'unlink' => false,
            ]];
        }
        $denied = AccountGuard::denial($actor, $user);
        $manageable = $denied === null;
        if (! $manageable && $denied !== 'self_target') {
            // Protected from this actor: reveal nothing about the account beyond "protected".
            return $base + ['state' => 'protected', 'actions' => [
                'create' => false, 'link' => false, 'reset_password' => false, 'deactivate' => false, 'reactivate' => false, 'unlink' => false,
            ]];
        }
        $state = ! $user->is_active ? 'inactive' : ($user->must_change_password ? 'password_change_required' : 'active');

        return $base + [
            'state' => $state,
            'login_email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'actions' => [
                'create' => false, 'link' => false,
                'reset_password' => $manageable && $has('accounts.reset_password'),
                'deactivate' => $manageable && $user->is_active && $has('accounts.deactivate'),
                'reactivate' => $manageable && ! $user->is_active && ! $terminated && $has('accounts.activate'),
                'unlink' => $manageable && $has('accounts.link'),
            ],
        ];
    }

    // ───────────────────────── mutations ─────────────────────────

    /**
     * @param  array{email:string, password?:?string, status?:string, confirm_employee_uuid:?string, reason?:?string}  $data
     * @return array{user: User, temporary_password: ?string}
     */
    public function create(User $actor, int $employeeId, array $data): array
    {
        return $this->run($actor, 'create', $employeeId, function () use ($actor, $employeeId, $data) {
            $actor = $this->begin($actor, 'accounts.create');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $data['confirm_employee_uuid'] ?? null);
            if ($employee->employment_status === EmploymentStatus::Terminated) {
                throw new RbacDenied(409, 'employee_terminated', __('accounts.employee_terminated'));
            }
            if ($employee->user_id !== null) {
                throw new RbacDenied(409, 'already_linked', __('accounts.already_linked'));
            }
            $email = mb_strtolower(trim($data['email']));
            // Global uniqueness, soft-deleted rows included (matches the database constraint).
            if (User::withoutGlobalScopes()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => [__('accounts.email_taken')]]);
            }
            $generated = empty($data['password']);
            $plain = $generated ? PasswordPolicy::generate() : (string) $data['password'];
            $active = ($data['status'] ?? 'active') !== 'inactive';

            try {
                $user = new User;
                $user->forceFill([
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'email' => $email,
                    'password' => $plain,
                    'phone' => $employee->phone,
                    'role' => UserRole::Employee,
                    'is_active' => $active,
                    'is_super_admin' => false,
                    'must_change_password' => true,
                ]);
                $user->company_id = $actor->company_id;
                $user->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => [__('accounts.email_taken')]]);
            }
            $employee->user_id = $user->id;
            $employee->save();
            $this->auditOrFail('account.created', $user, null, [
                'employee_id' => $employee->id, 'login_email' => $email, 'status' => $active ? 'active' : 'inactive',
                'must_change_password' => true, 'password_source' => $generated ? 'generated' : 'manual',
            ], $actor, $this->reason($data['reason'] ?? null, false));

            return ['user' => $user, 'temporary_password' => $generated ? $plain : null];
        });
    }

    public function link(User $actor, int $employeeId, int $userId, ?string $confirm, ?string $reason): User
    {
        return $this->run($actor, 'link', $employeeId, function () use ($actor, $employeeId, $userId, $confirm, $reason) {
            $actor = $this->begin($actor, 'accounts.link');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $confirm);
            $reason = $this->reason($reason);
            if ($employee->employment_status === EmploymentStatus::Terminated) {
                throw new RbacDenied(409, 'employee_terminated', __('accounts.employee_terminated'));
            }
            if ($employee->user_id !== null) {
                throw new RbacDenied(409, 'already_linked', __('accounts.already_linked'));
            }
            $user = User::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($userId)->lockForUpdate()->first();
            if (! $user || $user->trashed()) {
                throw new RbacDenied(404, 'not_found', 'Not found.');
            }
            $this->guard($actor, $user);
            if (Employee::withoutGlobalScopes()->withTrashed()->where('user_id', $user->id)->exists()) {
                throw new RbacDenied(409, 'account_already_linked', __('accounts.account_already_linked'));
            }
            $employee->user_id = $user->id;
            $employee->save();
            $this->auditOrFail('account.linked', $user, null, ['employee_id' => $employee->id], $actor, $reason, $this->vis('account.linked', $user));

            return $user;
        });
    }

    public function unlink(User $actor, int $employeeId, ?string $confirm, ?string $reason): void
    {
        $this->run($actor, 'unlink', $employeeId, function () use ($actor, $employeeId, $confirm, $reason) {
            $actor = $this->begin($actor, 'accounts.link');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $confirm);
            $reason = $this->reason($reason);
            $user = $this->linkedUser($employee);
            if (! $user) {
                throw new RbacDenied(409, 'no_account', __('accounts.no_account'));
            }
            $this->guard($actor, $user);
            $wasActive = (bool) $user->is_active;
            $employee->user_id = null;
            $employee->save();
            // An unlinked login must never stay an uncontrolled active sign-in: switch it off and kill every session.
            // Role assignments are left exactly as they were (never added, never restored); re-linking keeps it inactive
            // and activation is a separate, explicitly authorised and audited step.
            if ($wasActive) {
                $user->forceFill(['is_active' => false])->save();
            }
            $this->revokeTokens($user);
            $this->rbac->assertAdministratorRemains($actor->company_id);
            $this->auditOrFail('account.unlinked', $user, ['employee_id' => $employee->id, 'is_active' => $wasActive], ['employee_id' => null, 'is_active' => false, 'sessions_revoked' => true, 'account_deactivated' => $wasActive], $actor, $reason, $this->vis('account.unlinked', $user));
        });
    }

    /** @return array{user: User, temporary_password: ?string} */
    public function resetPassword(User $actor, int $employeeId, ?string $confirm, ?string $reason, ?string $password = null): array
    {
        return $this->run($actor, 'reset_password', $employeeId, function () use ($actor, $employeeId, $confirm, $reason, $password) {
            $actor = $this->begin($actor, 'accounts.reset_password');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $confirm);
            $reason = $this->reason($reason);
            $user = $this->linkedUser($employee);
            if (! $user || $user->trashed()) {
                throw new RbacDenied(409, 'no_account', __('accounts.no_account'));
            }
            $this->guard($actor, $user);
            $generated = empty($password);
            $plain = $generated ? PasswordPolicy::generate() : $password;
            $user->forceFill(['password' => $plain, 'must_change_password' => true, 'password_changed_at' => null])->save();
            $this->revokeTokens($user);
            $this->auditOrFail('account.password_reset', $user, null, [
                'employee_id' => $employee->id, 'must_change_password' => true, 'sessions_revoked' => true, 'password_source' => $generated ? 'generated' : 'manual',
            ], $actor, $reason, $this->vis('account.password_reset', $user));

            return ['user' => $user, 'temporary_password' => $generated ? $plain : null];
        });
    }

    public function deactivate(User $actor, int $employeeId, ?string $confirm, ?string $reason): User
    {
        return $this->run($actor, 'deactivate', $employeeId, function () use ($actor, $employeeId, $confirm, $reason) {
            $actor = $this->begin($actor, 'accounts.deactivate');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $confirm);
            $reason = $this->reason($reason);
            $user = $this->linkedUser($employee);
            if (! $user || $user->trashed()) {
                throw new RbacDenied(409, 'no_account', __('accounts.no_account'));
            }
            $this->guard($actor, $user);
            if (! $user->is_active) {
                throw new RbacDenied(409, 'already_inactive', __('accounts.already_inactive'));
            }
            $user->forceFill(['is_active' => false])->save();
            $this->revokeTokens($user);
            $this->rbac->assertAdministratorRemains($actor->company_id);
            $this->auditOrFail('account.deactivated', $user, ['is_active' => true], ['is_active' => false, 'sessions_revoked' => true, 'employee_id' => $employee->id], $actor, $reason, $this->vis('account.deactivated', $user));

            return $user;
        });
    }

    public function reactivate(User $actor, int $employeeId, ?string $confirm, ?string $reason): User
    {
        return $this->run($actor, 'reactivate', $employeeId, function () use ($actor, $employeeId, $confirm, $reason) {
            $actor = $this->begin($actor, 'accounts.activate');
            $employee = $this->employee($actor, $employeeId);
            $this->confirm($employee, $confirm);
            $reason = $this->reason($reason);
            $user = $this->linkedUser($employee);
            if (! $user || $user->trashed()) {
                throw new RbacDenied(409, 'no_account', __('accounts.no_account'));
            }
            $this->guard($actor, $user);
            if ($user->is_active) {
                throw new RbacDenied(409, 'already_active', __('accounts.already_active'));
            }
            if ($employee->employment_status === EmploymentStatus::Terminated) {
                throw new RbacDenied(409, 'employee_terminated', __('accounts.reactivate_employee_first'));
            }
            // Sessions revoked earlier stay revoked and roles are not restored; the account simply may sign in again.
            $user->forceFill(['is_active' => true])->save();
            $this->auditOrFail('account.activated', $user, ['is_active' => false], ['is_active' => true, 'employee_id' => $employee->id, 'sessions_restored' => false], $actor, $reason, $this->vis('account.activated', $user));

            return $user;
        });
    }

    // ───────────── employee lifecycle that touches the linked account ─────────────

    /**
     * Deny an employee-record change when the linked account is protected from this actor (indirect takeover)
     * or when it is the actor's OWN account: nobody edits their own official employment record through employee
     * CRUD (self-service is limited to phone and picture on My Profile). Call inside the lifecycle transaction.
     */
    public function assertEmployeeMutable(User $actor, Employee $employee): void
    {
        if ($employee->user_id === null) {
            return;
        }
        $user = $this->linkedUser($employee);
        if (! $user) {
            return;
        }
        $code = AccountGuard::denial($actor, $user);
        if ($code !== null) {
            $e = new RbacDenied(403, $code, __('accounts.protected_denied'));
            $this->recordDenied($actor, $employee->id, $user->id, 'employee_record_change', $e);
            throw $e;
        }
    }

    /**
     * Ordinary employee update. Same protocol as every lifecycle mutation: tenant lock, fresh actor and
     * permission, employee lock, linked-account lock, then the status rules and protection checks run on the
     * locked rows. The generic update cannot enter or leave the terminated state.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEmployee(User $actor, int $employeeId, array $data): Employee
    {
        return $this->run($actor, 'employee_update', $employeeId, function () use ($actor, $employeeId, $data) {
            $actor = $this->begin($actor, 'employees.update');
            $employee = $this->employee($actor, $employeeId);
            if ($employee->trashed()) {
                throw new RbacDenied(404, 'not_found', 'Not found.');
            }
            if (array_key_exists('employment_status', $data)) {
                $new = $data['employment_status'];
                $cur = $employee->employment_status->value;
                if (($new === 'terminated' || $cur === 'terminated') && $new !== $cur) {
                    throw new RbacDenied(422, 'use_lifecycle_action', __('accounts.status_change_via_lifecycle'));
                }
            }
            unset($data['termination_date'], $data['user_id']);
            $this->assertEmployeeMutable($actor, $employee);
            // FINAL-V3: job title and manager must suit the RESULTING department. Only a changed title, manager or department is re-checked, so
            // an unrelated edit never fails because of older data (those rows are listed by the compatibility reports instead).
            $newDept = array_key_exists('department_id', $data) ? $data['department_id'] : $employee->department_id;
            $newTitle = array_key_exists('job_title_id', $data) ? $data['job_title_id'] : $employee->job_title_id;
            $newMgr = array_key_exists('manager_id', $data) ? $data['manager_id'] : $employee->manager_id;
            $elig = app(EmployeeEligibility::class);
            if ($newDept !== $employee->department_id || $newTitle !== $employee->job_title_id) {
                $elig->assertJobTitle((int) $actor->company_id, $newTitle, $newDept);
            }
            if ($newDept !== $employee->department_id || $newMgr !== $employee->manager_id) {
                $elig->assertManager((int) $actor->company_id, $employee->id, $newMgr, $newDept);
            }
            $before = $employee->only(array_keys($data));
            $employee->update($data);
            $this->auditOrFail('employee.updated', $employee, $before, $employee->only(array_keys($data)), $actor);

            return $employee;
        });
    }

    /**
     * Minimal candidate list for the Link action: same tenant, not trashed, not a platform account, not already
     * linked to any employee, and administrable by THIS actor (protected, privileged and self accounts are excluded).
     * Only id, name, email and active flag leave the server. Requires accounts.link; capped; never the RBAC directory.
     *
     * @return list<array{id:int,name:string,email:string,is_active:bool}>
     */
    public function linkCandidates(User $actor, ?string $search): array
    {
        $q = User::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('is_super_admin', false)
            ->whereNotIn('id', Employee::withoutGlobalScopes()->withTrashed()->whereNotNull('user_id')->select('user_id'))
            ->where('id', '!=', $actor->id);
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.mb_strtolower(addcslashes($search, '\\%_')).'%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]));
        }
        $out = [];
        foreach ($q->orderBy('email')->limit(200)->get() as $u) {
            if (AccountGuard::denial($actor, $u) === null) {
                $out[] = ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => (bool) $u->is_active];
                if (count($out) >= 25) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Credential check result for sign-in, decided under the same lock protocol as the lifecycle mutations:
     * tenant (shared, so concurrent sign-ins do not queue behind each other but do wait for a mutation),
     * then the account row. The password, activity and company are re-validated on the locked row and the
     * token is created in the same transaction, so a stale pre-lock decision can never mint a valid token
     * after a reset, change, deactivation, termination or unlink.
     *
     * @return array{0:string,1:?User,2:?string} [status, user, token] status: ok|failed|inactive|company_inactive
     */
    public function authenticate(User $seen, string $plain, string $device): array
    {
        return DB::transaction(function () use ($seen, $plain, $device) {
            if ($seen->company_id !== null) {
                Company::withoutGlobalScopes()->whereKey($seen->company_id)->sharedLock()->first();
            }
            $user = User::withoutGlobalScopes()->whereKey($seen->id)->lockForUpdate()->first();
            if (! $user || $user->trashed()) {
                return ['failed', null, null];
            }
            if ($user->password !== $seen->password && ! Hash::check($plain, $user->password)) {
                return ['failed', null, null];
            }
            if (! $user->is_active) {
                return ['inactive', null, null];
            }
            $company = Company::withoutGlobalScopes()->find($user->company_id);
            if (! $company || ! $company->is_active) {
                return ['company_inactive', null, null];
            }

            return ['ok', $user, $this->issueToken($user, $device)];
        });
    }

    public function terminate(User $actor, int $employeeId, ?string $reason, ?string $date): Employee
    {
        return $this->run($actor, 'terminate', $employeeId, function () use ($actor, $employeeId, $reason, $date) {
            $actor = $this->begin($actor, 'employees.update');
            $employee = $this->employee($actor, $employeeId);
            $reason = $this->reason($reason);
            if ($employee->employment_status === EmploymentStatus::Terminated) {
                throw new RbacDenied(409, 'already_terminated', __('accounts.already_terminated'));
            }
            $user = $this->linkedUser($employee);
            $deactivated = false;
            if ($user) {
                if ($user->id === $actor->id) {
                    throw new RbacDenied(403, 'self_target', __('accounts.protected_denied'));
                }
                $this->guard($actor, $user);
                if ($user->is_active) {
                    // Terminating also switches the login off, so the actor must hold that authority.
                    if (! PermissionResolver::has($actor, 'accounts.deactivate')) {
                        throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
                    }
                    $user->forceFill(['is_active' => false])->save();
                    $deactivated = true;
                }
                $this->revokeTokens($user);
            }
            $before = ['employment_status' => $employee->employment_status->value];
            $employee->employment_status = EmploymentStatus::Terminated;
            $employee->termination_date = $date ?: now()->toDateString();
            $employee->save();
            if ($user) {
                $this->rbac->assertAdministratorRemains($actor->company_id);
            }
            // Phase 3.4: live organizational assignments end with the employment (history is kept, positions stay and show as vacant).
            $assignmentsEnded = app(OrgService::class)->endForEmployee($actor, $employee, 'employee_terminated', $employee->termination_date->toDateString(), $reason);
            $this->auditOrFail('employee.terminated', $employee, $before, [
                'employment_status' => 'terminated', 'termination_date' => $employee->termination_date->toDateString(),
                'account_deactivated' => $deactivated, 'sessions_revoked' => $user !== null, 'org_assignments_ended' => $assignmentsEnded,
            ], $actor, $reason);
            if ($deactivated) {
                $this->auditOrFail('account.deactivated', $user, ['is_active' => true], ['is_active' => false, 'sessions_revoked' => true, 'cause' => 'employee_terminated', 'employee_id' => $employee->id], $actor, $reason, $this->vis('account.deactivated', $user));
            }

            return $employee;
        });
    }

    public function reactivateEmployee(User $actor, int $employeeId, ?string $reason): Employee
    {
        return $this->run($actor, 'reactivate_employee', $employeeId, function () use ($actor, $employeeId, $reason) {
            $actor = $this->begin($actor, 'employees.update');
            $employee = $this->employee($actor, $employeeId);
            $reason = $this->reason($reason);
            if ($employee->employment_status !== EmploymentStatus::Terminated) {
                throw new RbacDenied(409, 'not_terminated', __('accounts.not_terminated'));
            }
            $user = $this->linkedUser($employee);
            if ($user) {
                $this->guard($actor, $user);
            }
            $employee->employment_status = EmploymentStatus::Active;
            $employee->termination_date = null;
            $employee->save();
            // The login stays inactive and sessions stay revoked: reactivating the account is a separate, explicit step.
            $this->auditOrFail('employee.reactivated', $employee, ['employment_status' => 'terminated'], ['employment_status' => 'active', 'account_unchanged' => true], $actor, $reason);

            return $employee;
        });
    }

    public function deleteEmployee(User $actor, int $employeeId): void
    {
        $this->run($actor, 'delete_employee', $employeeId, function () use ($actor, $employeeId) {
            $actor = $this->begin($actor, 'employees.delete');
            $employee = $this->employee($actor, $employeeId);
            $user = $this->linkedUser($employee);
            $deactivated = false;
            if ($user) {
                if ($user->id === $actor->id) {
                    throw new RbacDenied(403, 'self_target', __('accounts.protected_denied'));
                }
                $this->guard($actor, $user);
                if ($user->is_active) {
                    $user->forceFill(['is_active' => false])->save();
                    $deactivated = true;
                }
                $this->revokeTokens($user);
                $this->rbac->assertAdministratorRemains($actor->company_id);
            }
            $old = ['employee_number' => $employee->employee_number, 'employment_status' => $employee->employment_status->value];
            $assignmentsEnded = app(OrgService::class)->endForEmployee($actor, $employee, 'employee_deleted', OrgService::today($actor->company_id), null);
            $employee->delete();
            $this->auditOrFail('employee.deleted', $employee, $old, ['account_deactivated' => $deactivated, 'sessions_revoked' => $user !== null, 'org_assignments_ended' => $assignmentsEnded], $actor);
            if ($deactivated) {
                $this->auditOrFail('account.deactivated', $user, ['is_active' => true], ['is_active' => false, 'sessions_revoked' => true, 'cause' => 'employee_deleted', 'employee_id' => $employee->id], $actor, null, $this->vis('account.deactivated', $user));
            }
        });
    }
}
