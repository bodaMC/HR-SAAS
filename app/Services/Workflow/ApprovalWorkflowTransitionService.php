<?php

namespace App\Services\Workflow;

use App\Models\ApprovalWorkflow;
use App\Models\CasualLeaveTracker;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\PermissionMonthlyBalance;
use App\Models\PermissionRequest;
use App\Models\User;
use App\Services\Leave\SickLeaveService;
use App\Services\Notification\WorkflowNotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovalWorkflowTransitionService
{
    public function __construct(
        protected WorkflowNotificationService $notificationService,
        protected ApprovalWorkflowGenerator $workflowGenerator,
        protected SickLeaveService $sickLeaveService
    ) {}

    /**
     * Approve the current step in an approval workflow.
     */
    public function approveStep(
        ApprovalWorkflow $workflow,
        User $actionedBy,
        ?string $comments = null
    ): ApprovalWorkflow {
        return DB::transaction(function () use ($workflow, $actionedBy, $comments) {
            $workflow = ApprovalWorkflow::where('id', $workflow->id)->lockForUpdate()->first();
            $step = $workflow->currentStep();

            if (! $step || $step->status !== 'pending') {
                throw new InvalidArgumentException('No pending step available for approval.');
            }

            // Mark current step approved
            $step->status = 'approved';
            $step->actioned_by_user_id = $actionedBy->id;
            $step->actioned_at = now();
            $step->comments = $comments;
            $step->save();

            // Notify requester of step approval
            $this->notificationService->notifyStepApproved($workflow, $step);

            // Advance step order
            $workflow->current_step_order++;
            $workflow->save();

            // Evaluate next step for absent approver skipping
            $targetDate = $workflow->approvable instanceof LeaveRequest
                ? $workflow->approvable->start_date
                : $workflow->approvable->date;

            $this->workflowGenerator->evaluateStepAdvancement($workflow, $targetDate);

            // If workflow reached end, complete final approval
            if ($workflow->current_step_order > $workflow->total_steps) {
                $workflow->status = 'approved';
                $workflow->save();

                $this->finalizeRequestApproval($workflow->approvable, $actionedBy);
            } else {
                // Notify next approver
                $this->notificationService->notifyNextApprover($workflow);
            }

            return $workflow->fresh(['steps']);
        });
    }

    /**
     * Reject the current step in an approval workflow.
     */
    public function rejectStep(
        ApprovalWorkflow $workflow,
        User $actionedBy,
        string $rejectionReason
    ): ApprovalWorkflow {
        return DB::transaction(function () use ($workflow, $actionedBy, $rejectionReason) {
            $workflow = ApprovalWorkflow::where('id', $workflow->id)->lockForUpdate()->first();
            $step = $workflow->currentStep();

            if (! $step || $step->status !== 'pending') {
                throw new InvalidArgumentException('No pending step available for rejection.');
            }

            $step->status = 'rejected';
            $step->actioned_by_user_id = $actionedBy->id;
            $step->actioned_at = now();
            $step->comments = $rejectionReason;
            $step->save();

            $workflow->status = 'rejected';
            $workflow->save();

            $approvable = $workflow->approvable;
            $approvable->status = 'rejected';
            $approvable->final_actioned_by_user_id = $actionedBy->id;
            $approvable->final_actioned_at = now();
            $approvable->rejection_reason = $rejectionReason;
            $approvable->save();

            // Revert any pending balance reservations
            $this->revertPendingBalances($approvable);

            // Notify requester of rejection
            $this->notificationService->notifyRejected($approvable, $rejectionReason);

            return $workflow->fresh(['steps']);
        });
    }

    /**
     * Trigger implicit approval at the end of the working day.
     */
    public function applyImplicitApproval(LeaveRequest|PermissionRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $lockedRequest = get_class($request)::where('id', $request->id)->lockForUpdate()->first();

            if ($lockedRequest->status !== 'pending') {
                return;
            }

            $workflow = $lockedRequest->workflow;
            if ($workflow) {
                $workflow->status = 'approved';
                $workflow->save();

                // Mark remaining steps as approved with implicit reason
                $workflow->steps()->where('status', 'pending')->update([
                    'status' => 'approved',
                    'actioned_at' => now(),
                    'comments' => 'Automatically approved at end of working day because no final decision was recorded.',
                ]);
            }

            $lockedRequest->status = 'approved';
            $lockedRequest->is_implicit_approval = true;
            $lockedRequest->implicit_approval_reason = 'Automatically approved at end of working day because no final decision was recorded.';
            $lockedRequest->final_actioned_at = now();
            $lockedRequest->save();

            $this->finalizeRequestApproval($lockedRequest, null);

            $this->notificationService->notifyImplicitApproval($lockedRequest);
        });
    }

    /**
     * Finalize balances upon final request approval.
     */
    public function finalizeRequestApproval(
        LeaveRequest|PermissionRequest $request,
        ?User $actionedBy
    ): void {
        $request->status = 'approved';
        $request->final_actioned_by_user_id = $actionedBy?->id;
        $request->final_actioned_at = now();
        $request->save();

        if ($request instanceof LeaveRequest) {
            $year = $request->start_date->year;
            $leaveType = $request->leaveType;
            $deductedDays = (float) $request->deducted_leave_days;

            // 1. If Casual Leave, increment casual tracker
            if ($leaveType->code === 'CASUAL') {
                $casualTracker = CasualLeaveTracker::firstOrCreate(
                    ['company_id' => $request->company_id, 'employee_id' => $request->employee_id, 'year' => $year],
                    ['max_quota_days' => 7.00, 'used_quota_days' => 0.00, 'pending_quota_days' => 0.00]
                );
                $casualTracker->used_quota_days += $deductedDays;
                $casualTracker->pending_quota_days = max(0.0, (float) $casualTracker->pending_quota_days - $deductedDays);
                $casualTracker->save();
            }

            // 2. If Sick Leave, update tracker and check 0.25-day penalty
            if ($leaveType->code === 'SICK') {
                $this->sickLeaveService->recordApprovedSickDays($request->employee, $year, $deductedDays);
            }

            // 3. Deduct from Annual Leave balance if leaveType is Annual or Casual
            if ($leaveType->code === 'ANNUAL' || $leaveType->deducts_from_annual_balance) {
                $annualAllocation = LeaveAllocation::where('employee_id', $request->employee_id)
                    ->where('year', $year)
                    ->whereHas('leaveType', fn ($q) => $q->where('code', 'ANNUAL'))
                    ->lockForUpdate()
                    ->first();

                if ($annualAllocation) {
                    $annualAllocation->used_days += $deductedDays;
                    $annualAllocation->pending_days = max(0.0, (float) $annualAllocation->pending_days - $deductedDays);
                    $annualAllocation->save();
                }
            }
        } elseif ($request instanceof PermissionRequest) {
            // Normal permission: update monthly used hours
            if ($request->type === 'normal') {
                $balance = PermissionMonthlyBalance::where('employee_id', $request->employee_id)
                    ->where('year', $request->date->year)
                    ->where('month', $request->date->month)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $balance->normal_used_hours += (float) $request->duration_hours;
                    $balance->normal_pending_hours = max(0.0, (float) $balance->normal_pending_hours - (float) $request->duration_hours);
                    $balance->save();
                }
            }
            // Compensatory permission: update compensatory balance used hours
            elseif ($request->type === 'compensatory') {
                $compBalance = $request->employee->compensatoryBalance;
                if ($compBalance) {
                    $compBalance->used_as_permission_hours += (float) $request->duration_hours;
                    $compBalance->save();
                }
            }
        }
    }

    /**
     * Revert pending balance reservations when a request is rejected or cancelled.
     */
    public function revertPendingBalances(LeaveRequest|PermissionRequest $request): void
    {
        if ($request instanceof LeaveRequest) {
            $year = $request->start_date->year;
            $leaveType = $request->leaveType;
            $days = (float) $request->deducted_leave_days;

            if ($leaveType->code === 'CASUAL') {
                $casualTracker = CasualLeaveTracker::where('employee_id', $request->employee_id)
                    ->where('year', $year)
                    ->first();
                if ($casualTracker) {
                    $casualTracker->pending_quota_days = max(0.0, (float) $casualTracker->pending_quota_days - $days);
                    $casualTracker->save();
                }
            }

            if ($leaveType->code === 'ANNUAL' || $leaveType->deducts_from_annual_balance) {
                $annualAllocation = LeaveAllocation::where('employee_id', $request->employee_id)
                    ->where('year', $year)
                    ->whereHas('leaveType', fn ($q) => $q->where('code', 'ANNUAL'))
                    ->first();
                if ($annualAllocation) {
                    $annualAllocation->pending_days = max(0.0, (float) $annualAllocation->pending_days - $days);
                    $annualAllocation->save();
                }
            }
        } elseif ($request instanceof PermissionRequest && $request->type === 'normal') {
            $balance = PermissionMonthlyBalance::where('employee_id', $request->employee_id)
                ->where('year', $request->date->year)
                ->where('month', $request->date->month)
                ->first();
            if ($balance) {
                $balance->normal_pending_hours = max(0.0, (float) $balance->normal_pending_hours - (float) $request->duration_hours);
                $balance->save();
            }
        }
    }
}
