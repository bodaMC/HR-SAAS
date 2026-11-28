<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JobTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * @mixin JobTitle
 */
class JobTitleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'department_availability' => $this->department_availability ?? 'all',
            'department_ids' => ($this->department_availability ?? 'all') === 'selected'
                ? DB::table('job_title_departments')->where('job_title_id', $this->id)->orderBy('department_id')->pluck('department_id')->map(fn ($x) => (int) $x)->all() : [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
