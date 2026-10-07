<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LeaveType;
use App\Models\User;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveType $leaveType): bool
    {
        return $user->company_id === $leaveType->company_id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    public function update(User $user, LeaveType $leaveType): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $leaveType->company_id;
    }

    public function delete(User $user, LeaveType $leaveType): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $leaveType->company_id;
    }
}
