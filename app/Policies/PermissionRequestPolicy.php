<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PermissionRequest;
use App\Models\User;

class PermissionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PermissionRequest $permissionRequest): bool
    {
        if ($user->company_id !== $permissionRequest->company_id) {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        if ($permissionRequest->employee?->user_id === $user->id) {
            return true;
        }

        if ($user->role === UserRole::Manager && $permissionRequest->employee?->isManagedBy($user)) {
            return true;
        }

        $userEmployee = $user->employee;
        if ($userEmployee && $permissionRequest->workflow) {
            return $permissionRequest->workflow->steps()
                ->where('approver_employee_id', $userEmployee->id)
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function actionStep(User $user, PermissionRequest $permissionRequest): bool
    {
        if ($user->company_id !== $permissionRequest->company_id) {
            return false;
        }

        if ($permissionRequest->status !== 'pending') {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        $userEmployee = $user->employee;
        if (! $userEmployee) {
            return false;
        }

        $currentStep = $permissionRequest->workflow?->currentStep();
        if (! $currentStep || $currentStep->status !== 'pending') {
            return false;
        }

        return $currentStep->approver_employee_id === $userEmployee->id;
    }

    public function cancel(User $user, PermissionRequest $permissionRequest): bool
    {
        if ($user->company_id !== $permissionRequest->company_id) {
            return false;
        }

        if ($permissionRequest->status !== 'pending') {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        return $permissionRequest->employee?->user_id === $user->id;
    }
}
