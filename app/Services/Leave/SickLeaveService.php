<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\SickLeaveTracker;

class SickLeaveService
{
    public const float POLICY_THRESHOLD_DAYS = 7.00;

    public const float EXCESS_DAY_PENALTY_FRACTION = 0.25;

    /**
     * Process approved sick days, updating the tracker and applying the 0.25-day penalty for excess days.
     */
    public function recordApprovedSickDays(Employee $employee, int $year, float $approvedDays): void
    {
        $tracker = SickLeaveTracker::firstOrCreate(
            [
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'year' => $year,
            ],
            [
                'policy_threshold_days' => self::POLICY_THRESHOLD_DAYS,
                'used_sick_days' => 0.00,
                'excess_sick_days' => 0.00,
                'total_penalty_deduction_days' => 0.00,
            ]
        );

        $previousUsed = (float) $tracker->used_sick_days;
        $newUsed = $previousUsed + $approvedDays;
        $tracker->used_sick_days = $newUsed;

        if ($newUsed > self::POLICY_THRESHOLD_DAYS) {
            $excessDays = $newUsed - self::POLICY_THRESHOLD_DAYS;
            $tracker->excess_sick_days = $excessDays;
            $newTotalPenalty = round($excessDays * self::EXCESS_DAY_PENALTY_FRACTION, 2);
            $addedPenalty = $newTotalPenalty - (float) $tracker->total_penalty_deduction_days;
            $tracker->total_penalty_deduction_days = $newTotalPenalty;

            // Deduct penalty from available Annual Leave allocation
            if ($addedPenalty > 0) {
                $annualAllocation = LeaveAllocation::where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->whereHas('leaveType', fn ($q) => $q->where('code', 'ANNUAL'))
                    ->lockForUpdate()
                    ->first();

                if ($annualAllocation) {
                    $annualAllocation->used_days += $addedPenalty;
                    $annualAllocation->save();
                }
            }
        }

        $tracker->save();
    }
}
