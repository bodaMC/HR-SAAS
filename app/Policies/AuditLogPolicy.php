<?php

namespace App\Policies;

use App\Models\User;
use App\Rbac\PermissionResolver;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionResolver::has($user, 'audit_logs.view') || PermissionResolver::has($user, 'audit_logs.view_operational');
    }

    /** Full access to every event of the tenant (System Super Admin). */
    public function viewAll(User $user): bool
    {
        return PermissionResolver::has($user, 'audit_logs.view');
    }
}
