<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
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
            'project' => $this->whenLoaded('project', function () {
                return $this->project ? [
                    'id' => $this->project->id,
                    'uuid' => $this->project->uuid,
                    'name' => $this->project->name,
                    'code' => $this->project->code,
                ] : null;
            }),
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'calendar_days_count' => $this->calendar_days_count,
            'deducted_leave_days' => (float) $this->deducted_leave_days,
            'reason' => $this->reason,
            'is_late_submission' => $this->is_late_submission,
            'medical_certificate_path' => $this->medical_certificate_path,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'is_implicit_approval' => $this->is_implicit_approval,
            'implicit_approval_reason' => $this->implicit_approval_reason,
            'final_actioned_at' => $this->final_actioned_at?->toISOString(),
            'workflow' => new ApprovalWorkflowResource($this->whenLoaded('workflow')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
