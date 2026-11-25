<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Rbac\PermissionResolver;

/** Phase 3.2: explicit permissions only (deny by default). */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionResolver::has($user, 'departments.view');
    }

    public function view(User $user, Department $department): bool
    {
        return PermissionResolver::has($user, 'departments.view') && $user->company_id === $department->company_id;
    }

    public function create(User $user): bool
    {
        return PermissionResolver::has($user, 'departments.create');
    }

    public function update(User $user, Department $department): bool
    {
        return PermissionResolver::has($user, 'departments.update') && $user->company_id === $department->company_id;
    }

    public function delete(User $user, Department $department): bool
    {
        return PermissionResolver::has($user, 'departments.delete') && $user->company_id === $department->company_id;
    }
}
