<?php

namespace App\Services\Permissions;

use App\Models\Company;
use App\Models\CompensatoryBalance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PermissionMonthlyBalance;
use App\Models\PermissionRequest;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PermissionAllowanceCalculator
{
    public const float NORMAL_MONTHLY_ALLOWANCE_HOURS = 2.00;

    public const float MINIMUM_NORMAL_PERMISSION_HOURS = 1.00;

    public const float MINIMUM_DEDUCTION_OR_COMPENSATORY_HOURS = 0.50;

    public const float DEPARTMENT_CAP_PERCENTAGE = 0.25;

    /**
     * Calculate the maximum allowed simultaneous/daily permission slots for a department.
     * HOD and Team Leader are excluded from the denominator. Fractional results round down (floor).
     */
    public function calculateDepartmentDailyCapacity(Department $department): int
    {
        // Eligible regular team members: excluding HOD and Team Leader
        $eligibleCount = $department->employees()
            ->where('is_hod', false)
            ->where('is_team_leader', false)
            ->count();

        return (int) floor($eligibleCount * self::DEPARTMENT_CAP_PERCENTAGE);
    }

    /**
     * Check if department capacity is exceeded for a given date.
     */
    public function isDepartmentCapacityExceeded(Department $department, Carbon|string $date): bool
    {
        $maxCapacity = $this->calculateDepartmentDailyCapacity($department);
        $dateStr = is_string($date) ? $date : $date->format('Y-m-d');

        // Count approved/pending permissions on this date for regular team members in department
        $activePermissionsCount = PermissionRequest::whereHas('employee', function ($query) use ($department) {
            $query->where('department_id', $department->id)
                ->where('is_hod', false)
                ->where('is_team_leader', false);
        })
            ->whereDate('date', $dateStr)
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        return $activePermissionsCount >= $maxCapacity;
    }

    /**
     * Get the maximum allowed permission duration for an employee on a given date.
     * Equal to 50% of the applicable daily working hours on that date.
     */
    public function getMaxDailyPermissionDuration(Company $company, Carbon|string $date): float
    {
        $schedule = $company->activeWorkSchedule();
        if (! $schedule) {
            return 4.50;
        }

        $dailyHours = $schedule->getDailyHoursForDate($date);

        return round($dailyHours / 2.0, 2);
    }

    /**
     * Validate the Ramadan permission rule:
     * - No permissions allowed during Ramadan.
     * - If Ramadan spans two calendar months, normal permission allowance across the two months combined is capped at 2.0h.
     */
    public function validateRamadanPermission(Employee $employee, Carbon|string $date, float $durationHours): bool
    {
        $schedule = $employee->company->activeWorkSchedule();
        if (! $schedule || ! $schedule->ramadan_start_date || ! $schedule->ramadan_end_date) {
            return true;
        }

        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;

        // No permissions allowed during the active Ramadan period
        if ($schedule->isRamadanDate($carbonDate)) {
            return false;
        }

        // If Ramadan spans two calendar months, check the combined 2-hour cap across both months
        $ramadanStart = $schedule->ramadan_start_date;
        $ramadanEnd = $schedule->ramadan_end_date;

        if ($ramadanStart->month !== $ramadanEnd->month) {
            $month1 = $ramadanStart->month;
            $month2 = $ramadanEnd->month;
            $year = $carbonDate->year;

            if ($carbonDate->month === $month1 || $carbonDate->month === $month2) {
                $usedInMonth1 = (float) PermissionMonthlyBalance::where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->where('month', $month1)
                    ->value('normal_used_hours') ?? 0.0;

                $usedInMonth2 = (float) PermissionMonthlyBalance::where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->where('month', $month2)
                    ->value('normal_used_hours') ?? 0.0;

                if (($usedInMonth1 + $usedInMonth2 + $durationHours) > self::NORMAL_MONTHLY_ALLOWANCE_HOURS) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Orchestrate all business rule validations for a permission request.
     *
     * @throws ValidationException
     */
    public function validatePermissionRequest(
        Employee $employee,
        string $type,
        Carbon $date,
        float $durationHours
    ): void {
        // 1. Minimum duration check
        if ($type === 'normal' && $durationHours < self::MINIMUM_NORMAL_PERMISSION_HOURS) {
            throw ValidationException::withMessages([
                'duration_hours' => ['Normal permissions require a minimum of '.self::MINIMUM_NORMAL_PERMISSION_HOURS.' hour(s).'],
            ]);
        }

        if (in_array($type, ['deduction', 'compensatory']) && $durationHours < self::MINIMUM_DEDUCTION_OR_COMPENSATORY_HOURS) {
            throw ValidationException::withMessages([
                'duration_hours' => ["Permission of type '{$type}' requires a minimum of ".self::MINIMUM_DEDUCTION_OR_COMPENSATORY_HOURS.' hour(s).'],
            ]);
        }

        // 2. Normal permission monthly allowance check
        if ($type === 'normal') {
            $balance = PermissionMonthlyBalance::where('employee_id', $employee->id)
                ->where('year', $date->year)
                ->where('month', $date->month)
                ->first();

            $usedAndPending = ($balance ? (float) $balance->normal_used_hours + (float) $balance->normal_pending_hours : 0.0);

            if (($usedAndPending + $durationHours) > self::NORMAL_MONTHLY_ALLOWANCE_HOURS) {
                $remaining = max(0.0, self::NORMAL_MONTHLY_ALLOWANCE_HOURS - $usedAndPending);
                throw ValidationException::withMessages([
                    'duration_hours' => ["Insufficient normal permission allowance. Remaining this month: {$remaining}h."],
                ]);
            }
        }

        // 3. Compensatory permission balance check
        if ($type === 'compensatory') {
            $compBalance = CompensatoryBalance::where('employee_id', $employee->id)
                ->where('company_id', $employee->company_id)
                ->first();

            $available = $compBalance ? (float) $compBalance->available_hours : 0.0;

            if ($durationHours > $available) {
                throw ValidationException::withMessages([
                    'duration_hours' => ["Insufficient compensatory balance. Available: {$available}h."],
                ]);
            }
        }

        // 4. Maximum daily duration (50% of working day)
        $maxDuration = $this->getMaxDailyPermissionDuration($employee->company, $date);
        if ($durationHours > $maxDuration) {
            throw ValidationException::withMessages([
                'duration_hours' => ["Permission duration cannot exceed 50% of the working day ({$maxDuration}h on this date)."],
            ]);
        }

        // 5. Ramadan rule
        if (! $this->validateRamadanPermission($employee, $date, $durationHours)) {
            throw ValidationException::withMessages([
                'date' => ['Permissions are not allowed during Ramadan, or the 2-hour Ramadan cross-month cap would be exceeded.'],
            ]);
        }
    }
}
