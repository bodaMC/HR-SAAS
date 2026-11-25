<?php

namespace App\Policies;

use App\Models\User;
use App\Rbac\PermissionResolver;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionResolver::has($user, 'audit_logs.view');
    }
}
