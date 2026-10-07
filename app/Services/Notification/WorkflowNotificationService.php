<?php

namespace App\Services\Notification;

use App\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\PermissionRequest;
use App\Models\User;

class WorkflowNotificationService
{
    /**
     * Notify approvers and requester on new request submission.
     */
    public function notifyRequestSubmitted(LeaveRequest|PermissionRequest $request): void
    {
        $requesterUser = $request->employee?->user;
        $requestType = $request instanceof LeaveRequest ? 'Leave' : 'Permission';

        if ($requesterUser) {
            $this->createNotification(
                $request->company_id,
                $requesterUser->id,
                'request_submitted',
                "{$requestType} Request Submitted",
                "Your {$requestType} request has been submitted successfully.",
                ['request_id' => $request->id, 'uuid' => $request->uuid, 'type' => $requestType]
            );
        }

        $workflow = $request->workflow;
        if ($workflow) {
            $currentStep = $workflow->currentStep();
            if ($currentStep && $currentStep->approverEmployee?->user) {
                $this->createNotification(
                    $request->company_id,
                    $currentStep->approverEmployee->user->id,
                    'approval_required',
                    "New {$requestType} Request Requires Approval",
                    "A new {$requestType} request from {$request->employee->first_name} {$request->employee->last_name} requires your approval.",
                    [
                        'request_id' => $request->id,
                        'uuid' => $request->uuid,
                        'type' => $requestType,
                        'workflow_id' => $workflow->id,
                        'step_id' => $currentStep->id,
                    ]
                );
            }
        }
    }

    /**
     * Notify requester that a workflow step was approved.
     */
    public function notifyStepApproved(ApprovalWorkflow $workflow, ApprovalWorkflowStep $step): void
    {
        $requesterUser = $workflow->approvable?->employee?->user;
        if (! $requesterUser) {
            return;
        }

        $requestType = $workflow->approvable instanceof LeaveRequest ? 'Leave' : 'Permission';
        $approverName = $step->approverEmployee
            ? "{$step->approverEmployee->first_name} {$step->approverEmployee->last_name}"
            : 'Approver';

        $this->createNotification(
            $workflow->company_id,
            $requesterUser->id,
            'step_approved',
            "{$requestType} Request Step Approved",
            "Your {$requestType} request was approved at Step {$step->step_order} ({$step->role_type}) by {$approverName}.",
            [
                'request_id' => $workflow->approvable_id,
                'type' => $requestType,
                'workflow_id' => $workflow->id,
                'step_order' => $step->step_order,
            ]
        );
    }

    /**
     * Notify the next approver in the workflow.
     */
    public function notifyNextApprover(ApprovalWorkflow $workflow): void
    {
        $currentStep = $workflow->currentStep();
        if (! $currentStep || ! $currentStep->approverEmployee?->user) {
            return;
        }

        $approvable = $workflow->approvable;
        $requestType = $approvable instanceof LeaveRequest ? 'Leave' : 'Permission';
        $employee = $approvable?->employee;
        $requesterName = $employee ? "{$employee->first_name} {$employee->last_name}" : 'An employee';

        $this->createNotification(
            $workflow->company_id,
            $currentStep->approverEmployee->user->id,
            'approval_required',
            "{$requestType} Request Requires Your Approval",
            "{$requesterName} submitted a {$requestType} request that is now awaiting your approval.",
            [
                'request_id' => $workflow->approvable_id,
                'type' => $requestType,
                'workflow_id' => $workflow->id,
                'step_id' => $currentStep->id,
            ]
        );
    }

    /**
     * Notify requester that their request was rejected.
     */
    public function notifyRejected(LeaveRequest|PermissionRequest $request, string $rejectionReason): void
    {
        $requesterUser = $request->employee?->user;
        if (! $requesterUser) {
            return;
        }

        $requestType = $request instanceof LeaveRequest ? 'Leave' : 'Permission';

        $this->createNotification(
            $request->company_id,
            $requesterUser->id,
            'request_rejected',
            "{$requestType} Request Rejected",
            "Your {$requestType} request has been rejected. Reason: {$rejectionReason}",
            [
                'request_id' => $request->id,
                'uuid' => $request->uuid,
                'type' => $requestType,
                'rejection_reason' => $rejectionReason,
            ]
        );
    }

    /**
     * Notify requester and HR of implicit approval.
     */
    public function notifyImplicitApproval(LeaveRequest|PermissionRequest $request): void
    {
        $requesterUser = $request->employee?->user;
        $requestType = $request instanceof LeaveRequest ? 'Leave' : 'Permission';

        if ($requesterUser) {
            $this->createNotification(
                $request->company_id,
                $requesterUser->id,
                'implicit_approval',
                "{$requestType} Request Implicitly Approved",
                "Your {$requestType} request was automatically approved at the end of the working day.",
                ['request_id' => $request->id, 'uuid' => $request->uuid, 'type' => $requestType]
            );
        }

        // Notify HR/Admin users
        $hrUsers = User::where('company_id', $request->company_id)
            ->whereIn('role', [UserRole::Owner, UserRole::Admin])
            ->get();

        foreach ($hrUsers as $hrUser) {
            $this->createNotification(
                $request->company_id,
                $hrUser->id,
                'implicit_approval_notice',
                'Implicit Approval Processed',
                "A {$requestType} request for {$request->employee->first_name} {$request->employee->last_name} was implicitly approved at end of working day.",
                ['request_id' => $request->id, 'uuid' => $request->uuid, 'type' => $requestType]
            );
        }
    }

    /**
     * Notify HR/Admin when CEO submits a self-approved request.
     */
    public function notifyHrOfCeoSubmission(LeaveRequest|PermissionRequest $request): void
    {
        $requestType = $request instanceof LeaveRequest ? 'Leave' : 'Permission';
        $hrUsers = User::where('company_id', $request->company_id)
            ->whereIn('role', [UserRole::Owner, UserRole::Admin])
            ->get();

        foreach ($hrUsers as $hrUser) {
            $this->createNotification(
                $request->company_id,
                $hrUser->id,
                'ceo_request_recorded',
                "CEO {$requestType} Request Recorded",
                "CEO {$request->employee->first_name} {$request->employee->last_name} submitted a {$requestType} request (auto-approved).",
                ['request_id' => $request->id, 'uuid' => $request->uuid, 'type' => $requestType]
            );
        }
    }

    /**
     * Create a notification record.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function createNotification(
        int $companyId,
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null
    ): Notification {
        return Notification::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
