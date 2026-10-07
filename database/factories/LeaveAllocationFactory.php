<?php

namespace Database\Factories;

use App\Models\LeaveAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveAllocation>
 */
class LeaveAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'year' => (int) date('Y'),
            'statutory_entitlement_days' => 21.00,
            'pro_rata_factor' => 1.0000,
            'allocated_days' => 21.00,
            'carried_over_days' => 0.00,
            'converted_from_compensatory_days' => 0.00,
            'used_days' => 0.00,
            'pending_days' => 0.00,
        ];
    }

    public function withBalance(float $used = 5.00, float $pending = 2.00): static
    {
        return $this->state(fn (array $attrs) => [
            'used_days' => $used,
            'pending_days' => $pending,
        ]);
    }
}
