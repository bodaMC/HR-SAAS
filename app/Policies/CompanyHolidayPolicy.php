<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CompanyHoliday;
use App\Models\User;

class CompanyHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    public function delete(User $user, CompanyHoliday $holiday): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $holiday->company_id;
    }
}
