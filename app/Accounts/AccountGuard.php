<?php

namespace App\Accounts;

use App\Enums\UserRole;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Rbac\Registry;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.3 protected-account boundary. Single place that decides whether ONE actor may
 * administer ONE target account (reset, link/unlink, activate/deactivate, terminate/delete via
 * the employee record). It evaluates effective privileges AND protected role membership AND
 * account classification, never a role display name alone.
 *
 * Returns null when allowed, otherwise a stable denial code.
 */
final class AccountGuard
{
    /** Permission codes that make an account administratively privileged. @return list<string> */
    public static function administrativeCodes(): array
    {
        $codes = array_filter(Registry::assignable(), fn (string $c) => str_starts_with($c, 'roles.') || str_starts_with($c, 'permissions.') || str_starts_with($c, 'accounts.'));

        return array_values(array_unique(array_merge($codes, Registry::protectedCodes())));
    }

    /** True when the user holds the protected System Super Admin role of their own company. */
    public static function isSystemAdmin(User $user): bool
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->where('ur.company_id', $user->company_id)->where('r.company_id', $user->company_id)
            ->where('r.key', Registry::SYSTEM_ADMIN)->exists();
    }

    /** @return list<string> keys of system / protected roles the account holds */
    public static function protectedRoleKeys(User $user): array
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->where('ur.company_id', $user->company_id)
            ->where(fn ($q) => $q->where('r.is_system', true)->orWhere('r.is_protected', true))
            ->orderBy('r.key')->pluck('r.key')->all();
    }

    /** Whether the account is protected from a NON System Super Admin actor, ignoring the actor's own scope. */
    public static function isProtectedAccount(User $target): bool
    {
        if ($target->isSuperAdmin() || self::protectedRoleKeys($target)) {
            return true;
        }
        $role = $target->role instanceof UserRole ? $target->role : UserRole::tryFrom((string) $target->role);
        if (in_array($role, [UserRole::Owner, UserRole::Admin], true)) {
            return true;
        }

        return count(array_intersect(PermissionResolver::latent($target), self::administrativeCodes())) > 0;
    }

    public static function denial(User $actor, User $target): ?string
    {
        if ($target->isSuperAdmin()) {
            return 'protected_platform_account';
        }
        if ($actor->company_id !== $target->company_id) {
            return 'cross_tenant';
        }
        if ($actor->id === $target->id) {
            return 'self_target';
        }
        if (! $actor->is_active || $actor->isSuperAdmin()) {
            return 'actor_not_eligible';
        }
        if (self::isSystemAdmin($actor)) {
            // System Super Admin: every ordinary or HR account of its own tenant. The singleton
            // role itself is only ever changed by the trusted operator procedure.
            return null;
        }
        if (self::protectedRoleKeys($target)) {
            return 'protected_role_holder';
        }
        $role = $target->role instanceof UserRole ? $target->role : UserRole::tryFrom((string) $target->role);
        if (in_array($role, [UserRole::Owner, UserRole::Admin], true)) {
            return 'protected_classification';
        }
        $targetCodes = PermissionResolver::latent($target);
        if (array_intersect($targetCodes, self::administrativeCodes())) {
            return 'protected_privileges';
        }
        if (array_diff($targetCodes, PermissionResolver::for($actor))) {
            return 'exceeds_actor_scope';
        }

        return null;
    }
}
