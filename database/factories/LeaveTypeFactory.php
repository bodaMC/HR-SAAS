<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Annual Leave',
            'code' => 'ANNUAL',
            'description' => 'Standard annual leave entitlement.',
            'unit' => 'days',
            'requires_approval' => true,
            'deducts_from_annual_balance' => false,
            'is_paid' => true,
            'max_days_per_year' => null,
            'max_consecutive_days' => null,
            'requires_attachment' => false,
            'is_active' => true,
        ];
    }

    public function casual(): static
    {
        return $this->state(fn (array $attrs) => [
            'name' => 'Casual Leave',
            'code' => 'CASUAL',
            'description' => 'Casual/emergency leave (max 7 days/year, max 2 consecutive).',
            'deducts_from_annual_balance' => true,
            'max_days_per_year' => 7.00,
            'max_consecutive_days' => 2,
        ]);
    }

    public function sick(): static
    {
        return $this->state(fn (array $attrs) => [
            'name' => 'Sick Leave',
            'code' => 'SICK',
            'description' => 'Sick leave (7-day policy threshold).',
            'deducts_from_annual_balance' => false,
            'requires_attachment' => true,
        ]);
    }
}
