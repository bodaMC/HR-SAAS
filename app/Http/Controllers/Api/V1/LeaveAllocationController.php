<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leave\CalculateStatutoryAllocationRequest;
use App\Http\Requests\Api\V1\Leave\StoreLeaveAllocationRequest;
use App\Http\Resources\Api\V1\LeaveAllocationResource;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogger;
use App\Services\Leave\AnnualLeaveEntitlementCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class LeaveAllocationController extends Controller
{
    public function __construct(
        protected AnnualLeaveEntitlementCalculator $entitlementCalculator,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * List leave allocations.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = LeaveAllocation::with(['employee', 'leaveType']);

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

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        return LeaveAllocationResource::collection($query->get());
    }

    /**
     * Calculate statutory entitlement under Egyptian Labor Law No. 14 of 2025.
     */
    public function calculateStatutory(CalculateStatutoryAllocationRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $year = $request->integer('year', (int) date('Y'));
        $shouldPersist = $request->boolean('persist', true);

        $calculation = $this->entitlementCalculator->calculate($employee, $year);

        $allocation = null;
        if ($shouldPersist) {
            $annualLeaveType = LeaveType::where('company_id', $employee->company_id)
                ->where('code', 'ANNUAL')
                ->firstOrFail();

            $allocation = LeaveAllocation::updateOrCreate(
                [
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $annualLeaveType->id,
                    'year' => $year,
                ],
                [
                    'statutory_entitlement_days' => $calculation['statutory_entitlement_days'],
                    'pro_rata_factor' => $calculation['pro_rata_factor'],
                    'allocated_days' => $calculation['allocated_days'],
                ]
            );

            $this->auditLogger->log('calculate_statutory_allocation', $allocation, null, $calculation);
        }

        return response()->json([
            'statutory_law' => 'Egyptian Labor Law No. 14 of 2025',
            'calculation' => $calculation,
            'allocation' => $allocation ? new LeaveAllocationResource($allocation->load(['employee', 'leaveType'])) : null,
        ]);
    }

    /**
     * Store or manually adjust a leave allocation.
     */
    public function store(StoreLeaveAllocationRequest $request): JsonResponse
    {
        $this->authorize('manage', LeaveAllocation::class);

        $allocation = LeaveAllocation::updateOrCreate(
            [
                'company_id' => auth()->user()->company_id,
                'employee_id' => $request->integer('employee_id'),
                'leave_type_id' => $request->integer('leave_type_id'),
                'year' => $request->integer('year'),
            ],
            [
                'allocated_days' => $request->input('allocated_days', $request->input('total_allocated_days', 0)),
                'carried_over_days' => $request->input('carried_over_days', 0),
                'used_days' => $request->input('used_days', 0),
                'pending_days' => $request->input('pending_days', 0),
            ]
        );

        $this->auditLogger->log('store_leave_allocation', $allocation, null, $allocation->toArray());

        return (new LeaveAllocationResource($allocation->load(['employee', 'leaveType'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * View a specific allocation.
     */
    public function show(LeaveAllocation $leaveAllocation): LeaveAllocationResource
    {
        $this->authorize('view', $leaveAllocation);

        return new LeaveAllocationResource($leaveAllocation->load(['employee', 'leaveType']));
    }
}
