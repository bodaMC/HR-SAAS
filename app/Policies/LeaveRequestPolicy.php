<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->company_id !== $leaveRequest->company_id) {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        if ($leaveRequest->employee?->user_id === $user->id) {
            return true;
        }

        if ($user->role === UserRole::Manager && $leaveRequest->employee?->isManagedBy($user)) {
            return true;
        }

        // Check if user's employee is an approver on this workflow
        $userEmployee = $user->employee;
        if ($userEmployee && $leaveRequest->workflow) {
            return $leaveRequest->workflow->steps()
                ->where('approver_employee_id', $userEmployee->id)
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function actionStep(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->company_id !== $leaveRequest->company_id) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        // Owners / Admins have administrative override authority
        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        $userEmployee = $user->employee;
        if (! $userEmployee) {
            return false;
        }

        $currentStep = $leaveRequest->workflow?->currentStep();
        if (! $currentStep || $currentStep->status !== 'pending') {
            return false;
        }

        return $currentStep->approver_employee_id === $userEmployee->id;
    }

    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($user->company_id !== $leaveRequest->company_id) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            return true;
        }

        return $leaveRequest->employee?->user_id === $user->id;
    }
}
