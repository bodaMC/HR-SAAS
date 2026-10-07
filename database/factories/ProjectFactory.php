<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'code' => strtoupper(fake()->unique()->lexify('PRJ-???')),
            'description' => fake()->sentence(),
            'start_date' => now()->subMonths(2)->format('Y-m-d'),
            'end_date' => now()->addMonths(4)->format('Y-m-d'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attrs) => ['is_active' => false]);
    }
}
