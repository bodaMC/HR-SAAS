<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'employees' => $this->whenLoaded('employees', function () {
                return $this->employees->map(function ($emp) {
                    return [
                        'id' => $emp->id,
                        'uuid' => $emp->uuid,
                        'first_name' => $emp->first_name,
                        'last_name' => $emp->last_name,
                        'employee_number' => $emp->employee_number,
                        'is_project_engineer' => (bool) ($emp->pivot?->is_project_engineer ?? false),
                        'assigned_at' => $emp->pivot?->assigned_at,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
