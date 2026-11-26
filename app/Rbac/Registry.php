<?php

namespace App\Rbac;

/** Read-only access to config/rbac.php. */
final class Registry
{
    /** @return array<string, list<string>> */
    public static function groups(): array
    {
        return config('rbac.groups');
    }

    /** Permissions that can be enforced today and assigned through the tenant API. @return list<string> */
    public static function assignable(): array
    {
        return array_values(array_merge(...array_values(self::groups())));
    }

    /** @return list<string> */
    public static function protectedCodes(): array
    {
        return config('rbac.protected');
    }

    /** @return list<string> */
    public static function reserved(): array
    {
        return config('rbac.reserved');
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_merge(self::assignable(), self::protectedCodes(), self::reserved()));
    }

    public static function isAssignable(string $code): bool
    {
        return in_array($code, self::assignable(), true);
    }

    /** @return array<string, mixed> */
    public static function systemRole(string $key): array
    {
        $def = config("rbac.system_roles.$key");
        if (($def['permissions'] ?? null) === 'all_plus_protected') {
            $def['permissions'] = array_values(array_unique(array_merge(self::assignable(), self::protectedCodes())));
        }
        if (($def['delegates'] ?? null) === 'assignable') {
            $def['delegates'] = self::assignable();
        }

        return $def;
    }

    /** Key of the protected, singleton tenant role. */
    public const SYSTEM_ADMIN = 'system_super_admin';
}
