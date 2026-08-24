<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewPersonalData = $request->user()?->can('viewPersonalData', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'employment_status' => $this->employment_status instanceof EmploymentStatus
                ? $this->employment_status->value
                : $this->employment_status,
            'department_id' => $this->department_id,
            'job_title_id' => $this->job_title_id,
            'manager_id' => $this->manager_id,
            'user_id' => $this->user_id,
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'job_title' => new JobTitleResource($this->whenLoaded('jobTitle')),
            'manager' => $this->whenLoaded('manager', function () {
                if (! $this->manager) {
                    return null;
                }

                return [
                    'id' => $this->manager->id,
                    'uuid' => $this->manager->uuid,
                    'employee_number' => $this->manager->employee_number,
                    'first_name' => $this->manager->first_name,
                    'last_name' => $this->manager->last_name,
                    'email' => $this->manager->email,
                ];
            }),
            'user' => $this->whenLoaded('user', function () {
                if (! $this->user) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            // Conditional sensitive personal data fields
            $this->mergeWhen($canViewPersonalData, [
                'phone' => $this->phone,
                'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
                'gender' => $this->gender instanceof Gender
                    ? $this->gender->value
                    : $this->gender,
                'national_id' => $this->national_id,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
