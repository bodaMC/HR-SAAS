<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\JobTitle;
use App\Models\User;

class JobTitlePolicy
{
    /**
     * Determine whether the user can view any job titles.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the job title.
     */
    public function view(User $user, JobTitle $jobTitle): bool
    {
        return $user->company_id === $jobTitle->company_id;
    }

    /**
     * Determine whether the user can create job titles.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can update the job title.
     */
    public function update(User $user, JobTitle $jobTitle): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $jobTitle->company_id;
    }

    /**
     * Determine whether the user can delete the job title.
     */
    public function delete(User $user, JobTitle $jobTitle): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $jobTitle->company_id;
    }
}
