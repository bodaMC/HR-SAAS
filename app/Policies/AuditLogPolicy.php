<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }
}
