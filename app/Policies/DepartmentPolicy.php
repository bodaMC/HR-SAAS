<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    /**
     * Determine whether the user can view any departments.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the department.
     */
    public function view(User $user, Department $department): bool
    {
        return $user->company_id === $department->company_id;
    }

    /**
     * Determine whether the user can create departments.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the department.
     */
    public function update(User $user, Department $department): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $department->company_id;
    }

    /**
     * Determine whether the user can delete the department.
     */
    public function delete(User $user, Department $department): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $department->company_id;
    }
}
