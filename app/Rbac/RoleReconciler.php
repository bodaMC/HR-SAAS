<?php

namespace App\Rbac;

use App\Accounts\AccountGuard;
use App\Models\Company;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditVisibility;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 3.3 idempotent reconciliation of the EXISTING protected system roles (HR Administrator and
 * System Super Admin) with the revised permission matrix in config/rbac.php.
 *
 * - Only the two system roles are changed. Role assignments (who holds what) are never touched, no
 *   account is elevated and legacy Owner/Admin authority is not restored.
 * - HR loses roles.* / permissions.* (and every delegation) and gains the approved operational and
 *   accounts.* permissions. System Super Admin gains the new codes (it already holds the rest).
 * - Lockout guard: HR's administration permissions are removed only when another active account of
 *   the tenant still holds both permission-administrator permissions. Otherwise the removal is HELD
 *   (reported as blocked) until the System Super Admin is provisioned with the operator command.
 * - Custom roles are never modified; those holding administrative codes are only reported.
 * - Safe to run repeatedly; dry-run changes nothing.
 */
class RoleReconciler
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function reconcile(Company $company, bool $dryRun): array
    {
        $report = ['company_id' => $company->id, 'slug' => $company->slug, 'roles' => [], 'blocked' => [], 'custom_roles_to_review' => []];
        $context = app(TenantContext::class);
        $previous = $context->get();
        $context->set($company);
        try {
            DB::transaction(function () use ($company, $dryRun, &$report) {
                Company::withoutGlobalScopes()->whereKey($company->id)->lockForUpdate()->first();
                PermissionResolver::flush();
                foreach (['hr_administrator', Registry::SYSTEM_ADMIN] as $key) {
                    $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', $key)->where('is_system', true)->first();
                    if (! $role) {
                        continue;
                    }
                    $def = Registry::systemRole($key);
                    $have = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->all();
                    $want = $def['permissions'];
                    $add = array_values(array_diff($want, $have));
                    $remove = array_values(array_diff($have, $want));
                    $haveDel = DB::table('role_delegations')->where('role_id', $role->id)->pluck('permission')->all();
                    $wantDel = $def['delegates'];
                    $addDel = array_values(array_diff($wantDel, $haveDel));
                    $removeDel = array_values(array_diff($haveDel, $wantDel));

                    $removesAdmin = (bool) array_intersect($remove, config('rbac.permission_administrator'));
                    if ($removesAdmin && ! $this->otherAdministratorExists($company->id, $role->id)) {
                        $report['blocked'][] = [
                            'role' => $key,
                            'held_removals' => $remove,
                            'reason' => 'No other active account would still hold roles.assign and permissions.manage. Provision the System Super Admin first (php artisan rbac:bootstrap-system-admin), then run this command again.',
                        ];
                        $remove = [];
                        $removeDel = [];
                    }
                    $report['roles'][] = ['role' => $key, 'add' => $add, 'remove' => $remove, 'delegations_add' => $addDel, 'delegations_remove' => $removeDel, 'changed' => (bool) ($add || $remove || $addDel || $removeDel)];
                    if ($dryRun || ! ($add || $remove || $addDel || $removeDel)) {
                        continue;
                    }
                    DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission', $remove)->delete();
                    foreach ($add as $code) {
                        DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission' => $code, 'created_at' => now()]);
                    }
                    DB::table('role_delegations')->where('role_id', $role->id)->whereIn('permission', $removeDel)->delete();
                    foreach ($addDel as $code) {
                        DB::table('role_delegations')->insert(['role_id' => $role->id, 'permission' => $code, 'created_at' => now()]);
                    }
                    PermissionResolver::flush();
                    if ($this->audit->log('rbac.roles_reconciled', $role, ['permissions_removed' => $remove, 'delegations_removed' => $removeDel], ['permissions_added' => $add, 'delegations_added' => $addDel], null, 'Phase 3.3 permission matrix reconciliation', AuditVisibility::RESTRICTED) === null) {
                        throw new RuntimeException('Audit record could not be written; reconciliation rolled back.');
                    }
                }
                $admin = AccountGuard::administrativeCodes();
                $custom = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('is_system', false)->get();
                foreach ($custom as $role) {
                    $codes = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->all();
                    $flag = array_values(array_intersect($codes, $admin));
                    $stale = array_values(array_diff($codes, Registry::assignable()));
                    if ($flag || $stale) {
                        $report['custom_roles_to_review'][] = ['role_id' => $role->id, 'name' => $role->name, 'administrative_permissions' => $flag, 'no_longer_assignable' => $stale];
                    }
                }
                $report['dry_run'] = $dryRun;
            });
        } finally {
            $context->set($previous);
        }

        return $report;
    }

    private function otherAdministratorExists(int $companyId, int $exceptRoleId): bool
    {
        $need = config('rbac.permission_administrator');
        $rows = DB::table('user_roles as ur')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.company_id', $companyId)->where('ur.role_id', '!=', $exceptRoleId)
            ->where('u.is_active', true)->whereNull('u.deleted_at')
            ->where(fn ($q) => $q->where('u.is_super_admin', false)->orWhereNull('u.is_super_admin'))
            ->whereIn('rp.permission', $need)->select('ur.user_id', 'rp.permission')->distinct()->get()->groupBy('user_id');

        return $rows->contains(fn ($p) => count(array_diff($need, $p->pluck('permission')->all())) === 0);
    }
}
