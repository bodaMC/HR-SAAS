<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompensatoryBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompensatoryBalance
 */
class CompensatoryBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', function () {
                return [
                    'id' => $this->employee->id,
                    'uuid' => $this->employee->uuid,
                    'employee_number' => $this->employee->employee_number,
                    'first_name' => $this->employee->first_name,
                    'last_name' => $this->employee->last_name,
                ];
            }),
            'total_earned_hours' => (float) $this->total_earned_hours,
            'converted_to_leave_hours' => (float) $this->converted_to_leave_hours,
            'used_as_permission_hours' => (float) $this->used_as_permission_hours,
            'available_hours' => (float) $this->available_hours,
            'convertible_days' => (int) floor((float) $this->available_hours / 9.0),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
