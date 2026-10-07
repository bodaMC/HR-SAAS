<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PermissionMonthlyBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PermissionMonthlyBalance
 */
class PermissionMonthlyBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'normal_allowance_hours' => (float) $this->normal_allowance_hours,
            'normal_used_hours' => (float) $this->normal_used_hours,
            'normal_pending_hours' => (float) $this->normal_pending_hours,
            'normal_remaining_hours' => (float) $this->normal_remaining_hours,
            'is_ramadan_affected' => $this->is_ramadan_affected,
        ];
    }
}
