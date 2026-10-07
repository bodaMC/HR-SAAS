<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'unit' => $this->unit,
            'requires_approval' => $this->requires_approval,
            'deducts_from_annual_balance' => $this->deducts_from_annual_balance,
            'is_paid' => $this->is_paid,
            'max_days_per_year' => $this->max_days_per_year ? (float) $this->max_days_per_year : null,
            'max_consecutive_days' => $this->max_consecutive_days,
            'requires_attachment' => $this->requires_attachment,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
