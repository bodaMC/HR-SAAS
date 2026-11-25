<?php

namespace App\Policies;

use App\Models\CompanyHoliday;
use App\Models\User;
use App\Rbac\PermissionResolver;

class CompanyHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return PermissionResolver::has($user, 'work_schedules.manage');
    }

    public function delete(User $user, CompanyHoliday $holiday): bool
    {
        return PermissionResolver::has($user, 'work_schedules.manage')
            && $user->company_id === $holiday->company_id;
    }
}
