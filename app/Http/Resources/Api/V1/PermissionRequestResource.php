<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PermissionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PermissionRequest
 */
class PermissionRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'employee' => $this->whenLoaded('employee', function () {
                return [
                    'id' => $this->employee->id,
                    'uuid' => $this->employee->uuid,
                    'employee_number' => $this->employee->employee_number,
                    'first_name' => $this->employee->first_name,
                    'last_name' => $this->employee->last_name,
                ];
            }),
            'type' => $this->type,
            'date' => $this->date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_hours' => (float) $this->duration_hours,
            'reason' => $this->reason,
            'is_late_submission' => $this->is_late_submission,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'is_implicit_approval' => $this->is_implicit_approval,
            'final_actioned_at' => $this->final_actioned_at?->toISOString(),
            'workflow' => new ApprovalWorkflowResource($this->whenLoaded('workflow')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
