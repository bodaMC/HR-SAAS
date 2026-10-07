<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveDayCalculator
{
    /**
     * Calculate billable/deducted leave days according to domain and statutory rules.
     */
    public function calculateDeductedDays(
        LeaveType $type,
        Carbon|string $startDate,
        Carbon|string $endDate,
        Company $company
    ): float {
        $start = is_string($startDate) ? Carbon::parse($startDate) : $startDate->copy();
        $end = is_string($endDate) ? Carbon::parse($endDate) : $endDate->copy();

        if ($start->greaterThan($end)) {
            return 0.0;
        }

        // Sick leave counts ALL calendar days (including Fri, Sat, Holidays)
        if ($type->code === 'SICK') {
            return (float) ($start->diffInDays($end) + 1);
        }

        $totalCalendarDays = $start->diffInDays($end) + 1;
        // Saturday is counted for Annual Leave ONLY IF total calendar duration > 5 days
        $countSaturday = ($type->code === 'ANNUAL' && $totalCalendarDays > 5);

        // Fetch company holidays in range
        $holidays = CompanyHoliday::where('company_id', $company->id)
            ->whereDate('start_date', '<=', $end->format('Y-m-d'))
            ->whereDate('end_date', '>=', $start->format('Y-m-d'))
            ->get();

        $deductedDays = 0.0;
        $current = $start->copy();

        while ($current->lte($end)) {
            // Friday is NEVER counted
            if ($current->isFriday()) {
                $current->addDay();

                continue;
            }

            // Saturday is NOT counted unless total duration > 5 days for Annual Leave
            if ($current->isSaturday() && ! $countSaturday) {
                $current->addDay();

                continue;
            }

            // Public Holidays are NEVER counted for Annual, Casual, or Special Leave
            if ($this->isHolidayDate($current, $holidays)) {
                $current->addDay();

                continue;
            }

            $deductedDays += 1.0;
            $current->addDay();
        }

        return $deductedDays;
    }

    /**
     * Check if a specific date intersects with any company public holidays.
     *
     * @param  Collection<int, CompanyHoliday>  $holidays
     */
    protected function isHolidayDate(Carbon $date, Collection $holidays): bool
    {
        $dateStr = $date->format('Y-m-d');

        return $holidays->contains(function (CompanyHoliday $holiday) use ($dateStr) {
            $hStart = $holiday->start_date->format('Y-m-d');
            $hEnd = $holiday->end_date->format('Y-m-d');

            return $dateStr >= $hStart && $dateStr <= $hEnd;
        });
    }
}
