<?php

namespace App\Rbac;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for effective permissions (deny by default).
 *
 * - Platform Super Admin has NO tenant permissions here (explicit tenant access
 *   is a separate, audited path).
 * - The legacy Owner/Admin account type grants NO RBAC permission (FINAL-V3: the
 *   transitional fallback was removed; nothing depends on audit or assignment history).
 * - Manager/Employee and every job title/department/position grant nothing.
 * - Everything else comes from explicit tenant role assignments.
 *
 * The result is memoized only within the current PHP process and flushed at
 * the start of every request and after every RBAC mutation, so a revoked
 * permission applies to the very next request with an existing token.
 */
final class PermissionResolver
{
    /** @var array<int, list<string>> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** @return list<string> */
    public static function for(User $user): array
    {
        if (isset(self::$memo[$user->id])) {
            return self::$memo[$user->id];
        }

        return self::$memo[$user->id] = $user->is_active ? self::latent($user) : [];
    }

    /**
     * Permissions the account's role assignments WOULD grant, ignoring whether it is currently
     * active. Used by the protected-account guard so that deactivating an administrator can
     * never make it look ordinary (never memoized, never used for authorization).
     *
     * @return list<string>
     */
    public static function latent(User $user): array
    {
        $codes = [];

        if (! $user->isSuperAdmin()) {
            $rows = DB::table('user_roles as ur')
                ->join('roles as r', function ($j) {
                    $j->on('r.id', '=', 'ur.role_id')->on('r.company_id', '=', 'ur.company_id');
                })
                ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
                ->where('ur.user_id', $user->id)
                ->where('ur.company_id', $user->company_id)
                ->select('rp.permission', 'r.is_system')
                ->get();

            // Ignore identifiers that are no longer assignable (registry changed). Protected
            // administration permissions count ONLY when they come from a system role that
            // trusted code created; a custom role can never carry them.
            $assignable = Registry::assignable();
            $protected = Registry::protectedCodes();
            foreach ($rows as $row) {
                if (in_array($row->permission, $assignable, true) || ($row->is_system && in_array($row->permission, $protected, true))) {
                    $codes[] = $row->permission;
                }
            }
            // FINAL-V3: there is deliberately NO fallback for the legacy Owner/Admin account
            // type. No role means no RBAC permissions, whatever happened before.
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * The explicit delegation boundary: permissions this user may grant to others.
     * Comes ONLY from role_delegations rows of the user's roles, intersected with
     * what the user holds and what is assignable. Holding alone is not enough.
     *
     * @return list<string>
     */
    public static function delegable(User $user): array
    {
        if (! $user->is_active || $user->isSuperAdmin()) {
            return [];
        }
        $codes = DB::table('user_roles as ur')
            ->join('role_delegations as rd', 'rd.role_id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->where('ur.company_id', $user->company_id)
            ->distinct()->pluck('rd.permission')->all();

        return array_values(array_intersect($codes, self::for($user), Registry::assignable()));
    }

    public static function has(User $user, string $permission): bool
    {
        return in_array($permission, self::for($user), true);
    }

    public static function isPermissionAdministrator(User $user): bool
    {
        $set = self::for($user);

        return count(array_diff(config('rbac.permission_administrator'), $set)) === 0;
    }
}
