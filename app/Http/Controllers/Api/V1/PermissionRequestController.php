<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Permissions\ActionPermissionRequestRequest;
use App\Http\Requests\Api\V1\Permissions\StorePermissionRequestRequest;
use App\Http\Resources\Api\V1\PermissionMonthlyBalanceResource;
use App\Http\Resources\Api\V1\PermissionRequestResource;
use App\Models\Employee;
use App\Models\PermissionMonthlyBalance;
use App\Models\PermissionRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\WorkflowNotificationService;
use App\Services\Permissions\PermissionAllowanceCalculator;
use App\Services\Workflow\ApprovalWorkflowGenerator;
use App\Services\Workflow\ApprovalWorkflowTransitionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PermissionRequestController extends Controller
{
    public function __construct(
        protected PermissionAllowanceCalculator $allowanceCalculator,
        protected ApprovalWorkflowGenerator $workflowGenerator,
        protected ApprovalWorkflowTransitionService $transitionService,
        protected WorkflowNotificationService $notificationService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * List permission requests.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = PermissionRequest::with(['employee', 'workflow.steps.approverEmployee']);

        if ($user->role === UserRole::Owner || $user->role === UserRole::Admin) {
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->integer('employee_id'));
            }
        } elseif ($user->role === UserRole::Manager) {
            $employee = $user->employee;
            $subordinateIds = $employee ? $employee->getAllSubordinateIds() : [];
            $allowedIds = array_merge($subordinateIds, $employee ? [$employee->id] : []);
            $query->whereIn('employee_id', $allowedIds);
        } else {
            $employee = $user->employee;
            $query->where('employee_id', $employee?->id ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->string('date'));
        }

        $requests = $query->latest('date')->paginate(20);

        return PermissionRequestResource::collection($requests);
    }

    /**
     * Get permission balance for an employee.
     */
    public function getBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $request->integer('employee_id', $user->employee?->id ?? 0);
        $employee = Employee::findOrFail($employeeId);

        $year = $request->integer('year', (int) date('Y'));
        $month = $request->integer('month', (int) date('n'));

        $balance = PermissionMonthlyBalance::firstOrCreate(
            [
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'normal_allowance_hours' => 2.00,
                'normal_used_hours' => 0.00,
                'normal_pending_hours' => 0.00,
                'normal_remaining_hours' => 2.00,
                'is_ramadan_affected' => false,
            ]
        );

        $compBalance = $employee->compensatoryBalance;

        return response()->json([
            'monthly_balance' => new PermissionMonthlyBalanceResource($balance),
            'compensatory_available_hours' => $compBalance ? (float) $compBalance->available_hours : 0.0,
        ]);
    }

    /**
     * Submit a permission request.
     */
    public function store(StorePermissionRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = null;

        if (($user->role === UserRole::Owner || $user->role === UserRole::Admin) && $request->filled('employee_id')) {
            $employee = Employee::find($request->integer('employee_id'));
        } else {
            $employee = $user->employee;
        }

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => ['User does not have an associated employee record.'],
            ]);
        }

        $type = $request->string('type')->toString();
        $date = Carbon::parse($request->string('date'));
        $durationHours = (float) $request->input('duration_hours');

        // Validate allowance and business rules using calculator
        $this->allowanceCalculator->validatePermissionRequest($employee, $type, $date, $durationHours);

        $permissionRequest = DB::transaction(function () use ($employee, $type, $date, $durationHours, $request) {
            $permReq = PermissionRequest::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'type' => $type,
                'date' => $date->toDateString(),
                'start_time' => $request->input('start_time'),
                'end_time' => $request->input('end_time'),
                'duration_hours' => $durationHours,
                'reason' => $request->input('reason'),
                'status' => 'pending',
            ]);

            // Reserve pending normal allowance if applicable
            if ($type === 'normal') {
                $balance = PermissionMonthlyBalance::firstOrCreate(
                    [
                        'company_id' => $employee->company_id,
                        'employee_id' => $employee->id,
                        'year' => $date->year,
                        'month' => $date->month,
                    ],
                    [
                        'normal_allowance_hours' => 2.00,
                        'normal_used_hours' => 0.00,
                        'normal_pending_hours' => 0.00,
                        'normal_remaining_hours' => 2.00,
                        'is_ramadan_affected' => false,
                    ]
                );
                $balance->normal_pending_hours += $durationHours;
                $balance->save();
            }

            // Generate Workflow
            $workflow = $this->workflowGenerator->generateForPermission($permReq);

            if ($workflow->status === 'approved') {
                $this->transitionService->finalizeRequestApproval($permReq, auth()->user());
                $this->notificationService->notifyHrOfCeoSubmission($permReq);
            } else {
                $this->notificationService->notifyRequestSubmitted($permReq);
            }

            $this->auditLogger->log('create_permission_request', $permReq, null, $permReq->toArray());

            return $permReq->fresh(['employee', 'workflow.steps.approverEmployee']);
        });

        return (new PermissionRequestResource($permissionRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * View permission request.
     */
    public function show(PermissionRequest $permissionRequest): PermissionRequestResource
    {
        $this->authorize('view', $permissionRequest);

        $permissionRequest->load(['employee', 'workflow.steps.approverEmployee', 'workflow.steps.actionedBy']);

        return new PermissionRequestResource($permissionRequest);
    }

    /**
     * Approve permission request step.
     */
    public function approve(ActionPermissionRequestRequest $request, PermissionRequest $permissionRequest): JsonResponse
    {
        $this->authorize('actionStep', $permissionRequest);

        $workflow = $permissionRequest->workflow;
        if (! $workflow) {
            throw new \RuntimeException('No active workflow found for this request.');
        }

        $oldStatus = $permissionRequest->status;
        $this->transitionService->approveStep($workflow, $request->user(), $request->input('comments'));

        $permissionRequest->refresh();
        $this->auditLogger->log('approve_permission_step', $permissionRequest, ['status' => $oldStatus], ['status' => $permissionRequest->status]);

        return response()->json([
            'message' => 'Permission request step approved.',
            'permission_request' => new PermissionRequestResource($permissionRequest->load(['employee', 'workflow.steps.approverEmployee'])),
        ]);
    }

    /**
     * Reject permission request.
     */
    public function reject(ActionPermissionRequestRequest $request, PermissionRequest $permissionRequest): JsonResponse
    {
        $this->authorize('actionStep', $permissionRequest);

        $reason = $request->input('rejection_reason', $request->input('comments', 'Rejected by approver.'));
        $workflow = $permissionRequest->workflow;
        if (! $workflow) {
            throw new \RuntimeException('No active workflow found for this request.');
        }

        $oldStatus = $permissionRequest->status;
        $this->transitionService->rejectStep($workflow, $request->user(), $reason);

        $permissionRequest->refresh();
        $this->auditLogger->log('reject_permission_request', $permissionRequest, ['status' => $oldStatus], ['status' => 'rejected', 'reason' => $reason]);

        return response()->json([
            'message' => 'Permission request rejected.',
            'permission_request' => new PermissionRequestResource($permissionRequest->load(['employee', 'workflow.steps.approverEmployee'])),
        ]);
    }

    /**
     * Cancel pending permission request.
     */
    public function cancel(PermissionRequest $permissionRequest): JsonResponse
    {
        $this->authorize('cancel', $permissionRequest);

        DB::transaction(function () use ($permissionRequest) {
            $this->transitionService->revertPendingBalances($permissionRequest);

            $permissionRequest->status = 'cancelled';
            $permissionRequest->save();

            if ($permissionRequest->workflow) {
                $permissionRequest->workflow->status = 'cancelled';
                $permissionRequest->workflow->save();
                $permissionRequest->workflow->steps()->where('status', 'pending')->update(['status' => 'cancelled']);
            }

            $this->auditLogger->log('cancel_permission_request', $permissionRequest, ['status' => 'pending'], ['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Permission request cancelled successfully.',
        ]);
    }
}
