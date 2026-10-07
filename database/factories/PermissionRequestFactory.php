<?php

namespace Database\Factories;

use App\Models\PermissionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PermissionRequest>
 */
class PermissionRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'normal',
            'date' => fake()->dateTimeBetween('+1 days', '+10 days')->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'duration_hours' => 2.00,
            'reason' => fake()->sentence(),
            'is_late_submission' => false,
            'status' => 'pending',
            'is_implicit_approval' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attrs) => ['status' => 'approved']);
    }
}
