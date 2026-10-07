<?php

namespace App\Services\Leave;

use App\Enums\EmploymentStatus;
use App\Models\CasualLeaveTracker;
use App\Models\Company;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\SickLeaveTracker;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class YearEndProcessor
{
    public function __construct(
        protected AnnualLeaveEntitlementCalculator $entitlementCalculator,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Execute year-end processing for a company.
     * - Carry forward unused Annual Leave balance.
     * - Reset Casual Leave quota to 7 (no carryover / no conversion).
     * - Reset Sick Leave tracker (no carryover).
     * - Preserve Compensatory Hours balance.
     */
    public function process(Company $company, int $year): array
    {
        $processedEmployees = 0;
        $activeEmployees = $company->employees()
            ->where('employment_status', EmploymentStatus::Active)
            ->get();

        $annualType = LeaveType::where('company_id', $company->id)
            ->where('code', 'ANNUAL')
            ->first();

        if (! $annualType) {
            return ['processed' => 0, 'message' => 'No Annual Leave type configured.'];
        }

        foreach ($activeEmployees as $employee) {
            DB::transaction(function () use ($company, $employee, $year, $annualType, &$processedEmployees) {
                // 1. Calculate remaining unused Annual Leave from year Y
                $currentAnnualAllocation = LeaveAllocation::where('employee_id', $employee->id)
                    ->where('leave_type_id', $annualType->id)
                    ->where('year', $year)
                    ->first();

                $unusedAnnualDays = $currentAnnualAllocation ? $currentAnnualAllocation->remaining_days : 0.0;

                // 2. Compute entitlement for next year Y+1
                $entitlement = $this->entitlementCalculator->calculate($employee, $year + 1);

                // 3. Create or update Year Y+1 Annual Leave Allocation
                $nextAllocation = LeaveAllocation::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'leave_type_id' => $annualType->id,
                        'year' => $year + 1,
                    ],
                    [
                        'statutory_entitlement_days' => $entitlement['statutory_entitlement_days'],
                        'pro_rata_factor' => $entitlement['pro_rata_factor'],
                        'allocated_days' => $entitlement['allocated_days'],
                        'carried_over_days' => $unusedAnnualDays, // ONLY Annual carries over!
                        'converted_from_compensatory_days' => 0.00,
                        'used_days' => 0.00,
                        'pending_days' => 0.00,
                    ]
                );

                // 4. Reset Casual Leave Tracker to 7 days (Zero carryover, zero conversion)
                CasualLeaveTracker::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'year' => $year + 1,
                    ],
                    [
                        'max_quota_days' => 7.00,
                        'used_quota_days' => 0.00,
                        'pending_quota_days' => 0.00,
                    ]
                );

                // 5. Reset Sick Leave Tracker (Zero carryover)
                SickLeaveTracker::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'year' => $year + 1,
                    ],
                    [
                        'policy_threshold_days' => 7.00,
                        'used_sick_days' => 0.00,
                        'excess_sick_days' => 0.00,
                        'total_penalty_deduction_days' => 0.00,
                    ]
                );

                $processedEmployees++;
            });
        }

        return [
            'processed' => $processedEmployees,
            'year' => $year,
            'next_year' => $year + 1,
        ];
    }
}
