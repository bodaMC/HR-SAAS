<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompensatoryConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompensatoryConversion
 */
class CompensatoryConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'employee_id' => $this->employee_id,
            'hours_converted' => (float) $this->hours_converted,
            'leave_days_added' => (float) $this->leave_days_added,
            'leave_allocation_id' => $this->leave_allocation_id,
            'daily_work_hours_snapshot' => (float) $this->daily_work_hours_snapshot,
            'actioned_by' => $this->whenLoaded('actionedBy', function () {
                return $this->actionedBy ? [
                    'id' => $this->actionedBy->id,
                    'name' => $this->actionedBy->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
