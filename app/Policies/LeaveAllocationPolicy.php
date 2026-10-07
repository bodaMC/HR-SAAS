<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LeaveAllocation;
use App\Models\User;

class LeaveAllocationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveAllocation $allocation): bool
    {
        if ($user->company_id !== $allocation->company_id) {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        return $allocation->employee?->user_id === $user->id;
    }

    public function manage(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }
}
