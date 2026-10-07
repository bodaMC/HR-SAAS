<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 days', '+10 days')->format('Y-m-d');
        $end = $start;

        return [
            'start_date' => $start,
            'end_date' => $end,
            'calendar_days_count' => 1,
            'deducted_leave_days' => 1.00,
            'reason' => fake()->sentence(),
            'is_late_submission' => false,
            'status' => 'pending',
            'is_implicit_approval' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'approved',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'rejected',
            'rejection_reason' => 'Not approved.',
        ]);
    }
}
