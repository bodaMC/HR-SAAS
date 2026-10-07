<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompanyWorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyWorkSchedule
 */
class CompanyWorkScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'standard_daily_hours' => (float) $this->standard_daily_hours,
            'work_start_time' => $this->work_start_time,
            'work_end_time' => $this->work_end_time,
            'is_friday_weekend' => (bool) $this->is_friday_weekend,
            'is_saturday_weekend' => (bool) $this->is_saturday_weekend,
            'implicit_approval_time' => $this->implicit_approval_time,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
