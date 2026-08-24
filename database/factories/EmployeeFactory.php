<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Note: company_id is set automatically from TenantContext via BelongsToCompany.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_number' => 'EMP-'.fake()->unique()->numerify('#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'gender' => fake()->randomElement([Gender::Male, Gender::Female]),
            'national_id' => fake()->numerify('##############'),
            'hire_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'employment_status' => EmploymentStatus::Active,
            'department_id' => null,
            'job_title_id' => null,
            'manager_id' => null,
            'user_id' => null,
        ];
    }

    /**
     * Set the employee status to terminated.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => EmploymentStatus::Terminated,
            'termination_date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Set the employee status to on leave.
     */
    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => EmploymentStatus::OnLeave,
        ]);
    }
}
