<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

class CompensatoryTimePolicy
{
    public function view(User $user, Employee $employee): bool
    {
        if ($user->company_id !== $employee->company_id) {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        if ($employee->user_id === $user->id) {
            return true;
        }

        if ($user->role === UserRole::Manager && $employee->isManagedBy($user)) {
            return true;
        }

        return false;
    }

    public function manage(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }
}
