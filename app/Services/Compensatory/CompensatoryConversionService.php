<?php

namespace App\Services\Compensatory;

use App\Models\CompensatoryBalance;
use App\Models\CompensatoryConversion;
use App\Models\CompensatoryLog;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompensatoryConversionService
{
    /**
     * Convert compensatory hours into an Annual Leave Day (manual HR action only).
     */
    public function convertHoursToLeaveDay(
        Employee $employee,
        int $year,
        User $actionedBy,
        ?float $customHoursThreshold = null
    ): CompensatoryConversion {
        return DB::transaction(function () use ($employee, $year, $actionedBy, $customHoursThreshold) {
            $balance = CompensatoryBalance::where('employee_id', $employee->id)
                ->where('company_id', $employee->company_id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new InvalidArgumentException('Employee has no compensatory balance record.');
            }

            // Determine conversion rate from active schedule or default 9.0 hours
            $schedule = $employee->company->activeWorkSchedule();
            $requiredHours = $customHoursThreshold ?? ($schedule ? (float) $schedule->standard_daily_hours : 9.00);

            if ($balance->available_hours < $requiredHours) {
                throw new InvalidArgumentException("Insufficient compensatory hours ({$balance->available_hours}h available, {$requiredHours}h required).");
            }

            // Target Annual Leave Allocation
            $annualType = LeaveType::where('company_id', $employee->company_id)
                ->where('code', 'ANNUAL')
                ->firstOrFail();

            $allocation = LeaveAllocation::firstOrCreate(
                [
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $annualType->id,
                    'year' => $year,
                ],
                [
                    'allocated_days' => 21.00,
                    'carried_over_days' => 0.00,
                    'converted_from_compensatory_days' => 0.00,
                    'used_days' => 0.00,
                    'pending_days' => 0.00,
                ]
            );

            // Update balances
            $balance->converted_to_leave_hours += $requiredHours;
            $balance->save();

            $allocation->converted_from_compensatory_days += 1.00;
            $allocation->save();

            // Create conversion record
            $conversion = CompensatoryConversion::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'hours_converted' => $requiredHours,
                'leave_days_added' => 1.00,
                'leave_allocation_id' => $allocation->id,
                'daily_work_hours_snapshot' => $requiredHours,
                'actioned_by_user_id' => $actionedBy->id,
            ]);

            // Create log entry
            CompensatoryLog::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'type' => 'converted_to_leave',
                'hours' => $requiredHours,
                'notes' => "Manually converted {$requiredHours} compensatory hours into 1 Annual Leave Day.",
                'reference_type' => CompensatoryConversion::class,
                'reference_id' => $conversion->id,
                'recorded_by_user_id' => $actionedBy->id,
            ]);

            return $conversion;
        });
    }
}
