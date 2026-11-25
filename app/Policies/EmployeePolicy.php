<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Rbac\PermissionResolver;

/**
 * Phase 3.2: explicit permissions only (deny by default). Owner/Admin/Manager
 * roles and job titles grant nothing here. Users may always read their own
 * employee record and its personal data (self-service).
 */
class EmployeePolicy
{
    private function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }

    public function viewAny(User $user): bool
    {
        return PermissionResolver::has($user, 'employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->company_id !== $employee->company_id) {
            return false;
        }

        return PermissionResolver::has($user, 'employees.view') || $this->isSelf($user, $employee);
    }

    /** Personal data: date_of_birth, gender, national_id, phone. */
    public function viewPersonalData(User $user, Employee $employee): bool
    {
        if ($user->company_id !== $employee->company_id) {
            return false;
        }

        return PermissionResolver::has($user, 'employees.view_personal_data') || $this->isSelf($user, $employee);
    }

    public function create(User $user): bool
    {
        return PermissionResolver::has($user, 'employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return PermissionResolver::has($user, 'employees.update') && $user->company_id === $employee->company_id;
    }

    public function delete(User $user, Employee $employee): bool
    {
        return PermissionResolver::has($user, 'employees.delete') && $user->company_id === $employee->company_id;
    }
}
