<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApprovalWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalWorkflow
 */
class ApprovalWorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'current_step_order' => $this->current_step_order,
            'total_steps' => $this->total_steps,
            'steps' => ApprovalWorkflowStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
