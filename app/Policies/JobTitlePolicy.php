<?php

namespace App\Policies;

use App\Models\JobTitle;
use App\Models\User;
use App\Rbac\PermissionResolver;

/** Phase 3.2: explicit permissions only (deny by default). */
class JobTitlePolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionResolver::has($user, 'job_titles.view');
    }

    public function view(User $user, JobTitle $jobTitle): bool
    {
        return PermissionResolver::has($user, 'job_titles.view') && $user->company_id === $jobTitle->company_id;
    }

    public function create(User $user): bool
    {
        return PermissionResolver::has($user, 'job_titles.create');
    }

    public function update(User $user, JobTitle $jobTitle): bool
    {
        return PermissionResolver::has($user, 'job_titles.update') && $user->company_id === $jobTitle->company_id;
    }

    public function delete(User $user, JobTitle $jobTitle): bool
    {
        return PermissionResolver::has($user, 'job_titles.delete') && $user->company_id === $jobTitle->company_id;
    }
}
