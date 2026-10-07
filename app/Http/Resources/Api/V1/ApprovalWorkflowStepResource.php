<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApprovalWorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalWorkflowStep
 */
class ApprovalWorkflowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'step_order' => $this->step_order,
            'role_type' => $this->role_type,
            'approver_employee' => $this->whenLoaded('approverEmployee', function () {
                return $this->approverEmployee ? [
                    'id' => $this->approverEmployee->id,
                    'uuid' => $this->approverEmployee->uuid,
                    'first_name' => $this->approverEmployee->first_name,
                    'last_name' => $this->approverEmployee->last_name,
                ] : null;
            }),
            'status' => $this->status,
            'skipped_reason' => $this->skipped_reason,
            'actioned_by' => $this->whenLoaded('actionedBy', function () {
                return $this->actionedBy ? [
                    'id' => $this->actionedBy->id,
                    'name' => $this->actionedBy->name,
                ] : null;
            }),
            'actioned_at' => $this->actioned_at?->toISOString(),
            'comments' => $this->comments,
        ];
    }
}
