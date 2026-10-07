<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompensatoryLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompensatoryLog
 */
class CompensatoryLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'hours' => (float) $this->hours,
            'balance_after_hours' => (float) $this->balance_after_hours,
            'overtime_date' => $this->overtime_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'created_by' => $this->whenLoaded('creator', function () {
                return $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
