<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /**
     * Determine whether the user can view any employee records.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the basic employee record.
     */
    public function view(User $user, Employee $employee): bool
    {
        if ($user->company_id !== $employee->company_id) {
            return false;
        }

        // Owners and Admins can view all company employees
        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        // Users can view their own employee record
        if ($employee->user_id === $user->id) {
            return true;
        }

        // Managers can view direct and indirect reports, and company directory
        return true;
    }

    /**
     * Determine whether the user can view sensitive personal data of the employee.
     *
     * Sensitive personal data includes date_of_birth, gender, national_id, phone.
     */
    public function viewPersonalData(User $user, Employee $employee): bool
    {
        if ($user->company_id !== $employee->company_id) {
            return false;
        }

        // 1. Company Owner and Admin have full access to company employees' personal data
        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        // 2. An Employee can always view their own personal data
        if ($employee->user_id === $user->id) {
            return true;
        }

        // 3. A Manager can ONLY view personal data for employees in their direct/indirect management hierarchy
        if ($user->role === UserRole::Manager) {
            return $employee->isManagedBy($user);
        }

        // 4. Regular employees cannot view other employees' personal data
        return false;
    }

    /**
     * Determine whether the user can create employees.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the employee.
     */
    public function update(User $user, Employee $employee): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $employee->company_id;
    }

    /**
     * Determine whether the user can delete the employee.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $employee->company_id;
    }
}
