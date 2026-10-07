<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leave\ActionLeaveRequestRequest;
use App\Http\Requests\Api\V1\Leave\StoreLeaveRequestRequest;
use App\Http\Resources\Api\V1\LeaveRequestResource;
use App\Models\CasualLeaveTracker;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogger;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Notification\WorkflowNotificationService;
use App\Services\Workflow\ApprovalWorkflowGenerator;
use App\Services\Workflow\ApprovalWorkflowTransitionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LeaveRequestController extends Controller
{
    public function __construct(
        protected LeaveDayCalculator $dayCalculator,
        protected ApprovalWorkflowGenerator $workflowGenerator,
        protected ApprovalWorkflowTransitionService $transitionService,
        protected WorkflowNotificationService $notificationService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * List leave requests.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = LeaveRequest::with(['employee', 'leaveType', 'project', 'workflow.steps.approverEmployee']);

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->integer('employee_id'));
            }
        } elseif ($user->role === UserRole::Manager) {
            $employee = $user->employee;
            $subordinateIds = $employee ? $employee->getAllSubordinateIds() : [];
            $allowedEmployeeIds = array_merge($subordinateIds, $employee ? [$employee->id] : []);
            $query->whereIn('employee_id', $allowedEmployeeIds);
        } else {
            $employee = $user->employee;
            $query->where('employee_id', $employee?->id ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('year')) {
            $query->whereYear('start_date', $request->integer('year'));
        }

        return LeaveRequestResource::collection($query->latest('created_at')->paginate(20));
    }

    /**
     * Submit a new leave request.
     */
    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $user = $request->user();

        if (($user->role === UserRole::Owner || $user->role === UserRole::Admin) && $request->filled('employee_id')) {
            $employee = Employee::findOrFail($request->integer('employee_id'));
        } else {
            $employee = $user->employee;
        }

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => ['User does not have an associated employee record.'],
            ]);
        }

        $leaveType = LeaveType::findOrFail($request->integer('leave_type_id'));
        $startDate = Carbon::parse($request->string('start_date'));
        $endDate = Carbon::parse($request->string('end_date'));
        $year = $startDate->year;

        $calendarDaysCount = (int) $startDate->diffInDays($endDate) + 1;
        $isSickLeave = $leaveType->code === 'SICK';
        $deductedDays = $this->dayCalculator->calculateDeductedDays($leaveType, $startDate, $endDate, $employee->company);

        if ($deductedDays <= 0) {
            throw ValidationException::withMessages([
                'start_date' => ['The selected dates contain zero working days.'],
            ]);
        }

        // Validate Casual Leave quota
        if ($leaveType->code === 'CASUAL') {
            if ($deductedDays > 2) {
                throw ValidationException::withMessages([
                    'end_date' => ['Casual leave cannot exceed 2 consecutive working days per request.'],
                ]);
            }

            $casualTracker = CasualLeaveTracker::firstOrCreate(
                ['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'year' => $year],
                ['max_quota_days' => 7.00, 'used_quota_days' => 0.00, 'pending_quota_days' => 0.00]
            );

            if (($casualTracker->used_quota_days + $casualTracker->pending_quota_days + $deductedDays) > $casualTracker->max_quota_days) {
                throw ValidationException::withMessages([
                    'leave_type_id' => ['Insufficient Casual Leave quota. Max 7 days allowed per year.'],
                ]);
            }
        }

        // Validate Annual Leave balance
        if ($leaveType->code === 'ANNUAL' || $leaveType->code === 'CASUAL' || $leaveType->deducts_from_annual_balance) {
            $annualAllocation = LeaveAllocation::where('employee_id', $employee->id)
                ->where('year', $year)
                ->whereHas('leaveType', fn ($q) => $q->where('code', 'ANNUAL'))
                ->first();

            $availableDays = $annualAllocation ? $annualAllocation->remaining_days : 0;
            if ($deductedDays > $availableDays) {
                throw ValidationException::withMessages([
                    'leave_type_id' => ["Insufficient Annual Leave balance. Requested: {$deductedDays} days, Available: {$availableDays} days."],
                ]);
            }
        }

        $leaveRequest = DB::transaction(function () use ($employee, $leaveType, $startDate, $endDate, $calendarDaysCount, $deductedDays, $request, $year) {
            $leaveReq = LeaveRequest::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'project_id' => $request->input('project_id'),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'calendar_days_count' => $calendarDaysCount,
                'deducted_leave_days' => $deductedDays,
                'reason' => $request->input('reason'),
                'status' => 'pending',
            ]);

            // Reserve pending Casual quota
            if ($leaveType->code === 'CASUAL') {
                $casualTracker = CasualLeaveTracker::firstOrCreate(
                    ['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'year' => $year],
                    ['max_quota_days' => 7.00, 'used_quota_days' => 0.00, 'pending_quota_days' => 0.00]
                );
                $casualTracker->pending_quota_days += $deductedDays;
                $casualTracker->save();
            }

            // Reserve pending Annual Leave
            if ($leaveType->code === 'ANNUAL' || $leaveType->code === 'CASUAL' || $leaveType->deducts_from_annual_balance) {
                $annualAllocation = LeaveAllocation::where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->whereHas('leaveType', fn ($q) => $q->where('code', 'ANNUAL'))
                    ->first();

                if ($annualAllocation) {
                    $annualAllocation->pending_days += $deductedDays;
                    $annualAllocation->save();
                }
            }

            // Generate dynamic approval workflow
            $workflow = $this->workflowGenerator->generateForLeave($leaveReq);

            if ($workflow->status === 'approved') {
                $this->transitionService->finalizeRequestApproval($leaveReq, auth()->user());
                $this->notificationService->notifyHrOfCeoSubmission($leaveReq);
            } else {
                $this->notificationService->notifyRequestSubmitted($leaveReq);
            }

            $this->auditLogger->log('create_leave_request', $leaveReq, null, $leaveReq->toArray());

            return $leaveReq->fresh(['employee', 'leaveType', 'project', 'workflow.steps.approverEmployee']);
        });

        return (new LeaveRequestResource($leaveRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * View leave request details.
     */
    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('view', $leaveRequest);
        $leaveRequest->load(['employee', 'leaveType', 'project', 'workflow.steps.approverEmployee', 'workflow.steps.actionedBy']);

        return new LeaveRequestResource($leaveRequest);
    }

    /**
     * Approve the current workflow step.
     */
    public function approve(ActionLeaveRequestRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('actionStep', $leaveRequest);

        $workflow = $leaveRequest->workflow;
        if (! $workflow) {
            throw new \RuntimeException('No active workflow found.');
        }

        $oldStatus = $leaveRequest->status;
        $this->transitionService->approveStep($workflow, $request->user(), $request->input('comments'));

        $leaveRequest->refresh();
        $this->auditLogger->log('approve_leave_step', $leaveRequest, ['status' => $oldStatus], ['status' => $leaveRequest->status]);

        return response()->json([
            'message' => 'Step approved successfully.',
            'leave_request' => new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType', 'project', 'workflow.steps.approverEmployee'])),
        ]);
    }

    /**
     * Reject the request at the current step.
     */
    public function reject(ActionLeaveRequestRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('actionStep', $leaveRequest);

        $reason = $request->input('rejection_reason', $request->input('comments', 'Rejected by approver.'));
        $workflow = $leaveRequest->workflow;
        if (! $workflow) {
            throw new \RuntimeException('No active workflow found.');
        }

        $oldStatus = $leaveRequest->status;
        $this->transitionService->rejectStep($workflow, $request->user(), $reason);

        $leaveRequest->refresh();
        $this->auditLogger->log('reject_leave_request', $leaveRequest, ['status' => $oldStatus], ['status' => 'rejected', 'reason' => $reason]);

        return response()->json([
            'message' => 'Leave request rejected.',
            'leave_request' => new LeaveRequestResource($leaveRequest->load(['employee', 'leaveType', 'project', 'workflow.steps.approverEmployee'])),
        ]);
    }

    /**
     * Cancel a pending leave request.
     */
    public function cancel(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('cancel', $leaveRequest);

        DB::transaction(function () use ($leaveRequest) {
            $this->transitionService->revertPendingBalances($leaveRequest);

            $leaveRequest->status = 'cancelled';
            $leaveRequest->save();

            if ($leaveRequest->workflow) {
                $leaveRequest->workflow->status = 'cancelled';
                $leaveRequest->workflow->save();
                $leaveRequest->workflow->steps()->where('status', 'pending')->update(['status' => 'cancelled']);
            }

            $this->auditLogger->log('cancel_leave_request', $leaveRequest, ['status' => 'pending'], ['status' => 'cancelled']);
        });

        return response()->json(['message' => 'Leave request cancelled successfully.']);
    }
}
