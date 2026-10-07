<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->company_id === $project->company_id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner || $user->role === UserRole::Admin;
    }

    public function update(User $user, Project $project): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $project->company_id;
    }

    public function delete(User $user, Project $project): bool
    {
        return ($user->role === UserRole::Owner || $user->role === UserRole::Admin)
            && $user->company_id === $project->company_id;
    }
}
