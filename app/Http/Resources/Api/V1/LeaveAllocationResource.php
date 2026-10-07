<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveAllocation
 */
class LeaveAllocationResource extends JsonResource
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
            'leave_type' => new LeaveTypeResource($this->whenLoaded('leaveType')),
            'year' => $this->year,
            'statutory_entitlement_days' => (float) $this->statutory_entitlement_days,
            'pro_rata_factor' => (float) $this->pro_rata_factor,
            'allocated_days' => (float) $this->allocated_days,
            'carried_over_days' => (float) $this->carried_over_days,
            'converted_from_compensatory_days' => (float) $this->converted_from_compensatory_days,
            'used_days' => (float) $this->used_days,
            'pending_days' => (float) $this->pending_days,
            'remaining_days' => (float) $this->remaining_days,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
