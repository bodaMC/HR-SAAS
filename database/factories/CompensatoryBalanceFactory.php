<?php

namespace Database\Factories;

use App\Models\CompensatoryBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompensatoryBalance>
 */
class CompensatoryBalanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'total_earned_hours' => 18.00,
            'used_as_permission_hours' => 0.00,
            'converted_to_leave_hours' => 0.00,
        ];
    }

    public function empty(): static
    {
        return $this->state(fn (array $attrs) => [
            'total_earned_hours' => 0.00,
        ]);
    }
}
