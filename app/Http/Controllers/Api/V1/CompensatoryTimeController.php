<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Compensatory\AccrueCompensatoryTimeRequest;
use App\Http\Requests\Api\V1\Compensatory\ConvertCompensatoryTimeRequest;
use App\Http\Resources\Api\V1\CompensatoryBalanceResource;
use App\Http\Resources\Api\V1\CompensatoryConversionResource;
use App\Models\CompensatoryBalance;
use App\Models\CompensatoryLog;
use App\Models\Employee;
use App\Services\Audit\AuditLogger;
use App\Services\Compensatory\CompensatoryConversionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CompensatoryTimeController extends Controller
{
    public function __construct(
        protected CompensatoryConversionService $conversionService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Get compensatory balance and history for an employee.
     */
    public function getBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $request->integer('employee_id', $user->employee?->id ?? 0);
        $employee = Employee::findOrFail($employeeId);

        $this->authorize('view', [CompensatoryBalance::class, $employee]);

        $balance = CompensatoryBalance::firstOrCreate(
            ['company_id' => $employee->company_id, 'employee_id' => $employee->id],
            [
                'total_earned_hours' => 0.00,
                'used_as_permission_hours' => 0.00,
                'converted_to_leave_hours' => 0.00,
            ]
        );

        $balance->load(['employee']);

        return response()->json([
            'balance' => new CompensatoryBalanceResource($balance),
        ]);
    }

    /**
     * Accrue compensatory overtime hours for an employee (HR/Admin only).
     */
    public function accrue(AccrueCompensatoryTimeRequest $request): JsonResponse
    {
        $this->authorize('manage', CompensatoryBalance::class);

        $employee = Employee::findOrFail($request->integer('employee_id'));
        $hours = (float) $request->input('hours');
        $overtimeDate = $request->filled('overtime_date') ? Carbon::parse($request->string('overtime_date')) : null;
        $reason = $request->string('reason')->toString();
        $user = $request->user();

        $balance = DB::transaction(function () use ($employee, $hours, $reason, $user) {
            $compBalance = CompensatoryBalance::firstOrCreate(
                ['company_id' => $employee->company_id, 'employee_id' => $employee->id],
                [
                    'total_earned_hours' => 0.00,
                    'used_as_permission_hours' => 0.00,
                    'converted_to_leave_hours' => 0.00,
                ]
            );

            $compBalance->total_earned_hours += $hours;
            $compBalance->save();

            CompensatoryLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'type' => 'accrual',
                'hours' => $hours,
                'notes' => $reason,
                'recorded_by_user_id' => $user->id,
            ]);

            $this->auditLogger->log('accrue_compensatory_time', $compBalance, null, [
                'employee_id' => $employee->id,
                'hours' => $hours,
                'reason' => $reason,
            ]);

            return $compBalance;
        });

        return (new CompensatoryBalanceResource($balance->load('employee')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Convert compensatory hours into Annual Leave Days (HR/Admin only).
     * Conversion rule: standard daily hours (default 9h) → 1 Annual Leave Day.
     * This is NEVER automated; always a manual HR action.
     */
    public function convert(ConvertCompensatoryTimeRequest $request): JsonResponse
    {
        $this->authorize('manage', CompensatoryBalance::class);

        $employee = Employee::findOrFail($request->integer('employee_id'));
        $year = $request->integer('year', (int) date('Y'));
        $user = $request->user();

        $customThreshold = $request->filled('custom_hours_threshold')
            ? (float) $request->input('custom_hours_threshold')
            : null;

        // Call the service once per standard-day threshold (default 9h per day)
        $conversion = $this->conversionService->convertHoursToLeaveDay($employee, $year, $user, $customThreshold);

        $this->auditLogger->log('convert_compensatory_to_leave', $conversion, null, $conversion->toArray());

        return response()->json([
            'message' => "Successfully converted {$conversion->hours_converted}h compensatory time into {$conversion->leave_days_added} Annual Leave day(s).",
            'conversion' => new CompensatoryConversionResource($conversion->load('actionedBy')),
            'balance' => new CompensatoryBalanceResource($employee->fresh()->compensatoryBalance->load('employee')),
        ]);
    }
}
