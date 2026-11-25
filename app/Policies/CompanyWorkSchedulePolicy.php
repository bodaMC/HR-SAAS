<?php

namespace App\Policies;

use App\Models\User;
use App\Rbac\PermissionResolver;

class CompanyWorkSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return PermissionResolver::has($user, 'work_schedules.manage');
    }
}
