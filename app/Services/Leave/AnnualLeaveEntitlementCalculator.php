<?php

namespace App\Services\Leave;

use App\Models\Employee;
use Carbon\Carbon;

class AnnualLeaveEntitlementCalculator
{
    /**
     * Statutory Entitlement Baseline Parameters (Egyptian Labor Law No. 14 of 2025).
     */
    public const float STATUTORY_FIRST_YEAR_DAYS = 15.00;

    public const float STATUTORY_STANDARD_DAYS = 21.00;

    public const float STATUTORY_SENIOR_DAYS = 30.00;

    public const float STATUTORY_DISABILITY_DAYS = 45.00;

    public const float STATUTORY_HAZARDOUS_ADDITIONAL_DAYS = 7.00;

    public const int SENIOR_AGE_THRESHOLD = 50;

    public const float SENIOR_INSURANCE_YEARS_THRESHOLD = 10.00;

    /**
     * Calculate the statutory and pro-rata annual leave entitlement for an employee in a given year.
     *
     * @return array{statutory_entitlement_days: float, pro_rata_factor: float, allocated_days: float}
     */
    public function calculate(Employee $employee, int $year): array
    {
        $statutoryDays = $this->determineStatutoryBaselineDays($employee, $year);

        // Calculate Pro-Rata factor if hired during the current year
        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;
        $startOfYear = Carbon::create($year, 1, 1);
        $endOfYear = Carbon::create($year, 12, 31);

        if ($hireDate && $hireDate->year === $year && $hireDate->greaterThan($startOfYear)) {
            $qualifyingDays = $hireDate->diffInDays($endOfYear) + 1;
            $totalDaysInYear = $startOfYear->diffInDays($endOfYear) + 1;
            $proRataFactor = round($qualifyingDays / $totalDaysInYear, 4);
            $allocatedDays = round($statutoryDays * $proRataFactor, 2);
        } else {
            $proRataFactor = 1.0000;
            $allocatedDays = $statutoryDays;
        }

        return [
            'statutory_entitlement_days' => $statutoryDays,
            'pro_rata_factor' => $proRataFactor,
            'allocated_days' => $allocatedDays,
        ];
    }

    /**
     * Determine the legal baseline entitlement in days based on Law No. 14 of 2025.
     */
    public function determineStatutoryBaselineDays(Employee $employee, int $year): float
    {
        // 1. Person with Disabilities / Special Needs (45 days)
        if ($employee->is_disabled) {
            return self::STATUTORY_DISABILITY_DAYS;
        }

        // 2. Seniority: Age >= 50 on Jan 1st of the leave year OR Insurance Years >= 10 (30 days)
        $ageOnJanFirst = $employee->getAgeAt(Carbon::create($year, 1, 1));
        $insuranceYears = (float) $employee->insurance_years;

        if ($ageOnJanFirst >= self::SENIOR_AGE_THRESHOLD || $insuranceYears >= self::SENIOR_INSURANCE_YEARS_THRESHOLD) {
            return self::STATUTORY_SENIOR_DAYS;
        }

        // 3. First year of service (tenure < 1 year on Jan 1st of leave year) (15 days)
        $tenureYears = $employee->getTenureYearsAt(Carbon::create($year, 1, 1));
        if ($tenureYears < 1.0) {
            return self::STATUTORY_FIRST_YEAR_DAYS;
        }

        // 4. Standard baseline (21 days)
        return self::STATUTORY_STANDARD_DAYS;
    }
}
