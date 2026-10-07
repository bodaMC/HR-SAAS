<?php

namespace App\Services\Workflow;

use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PermissionRequest;
use App\Services\Notification\WorkflowNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowGenerator
{
    public function __construct(
        protected WorkflowNotificationService $notificationService
    ) {}

    /**
     * Generate dynamic approval workflow for a LeaveRequest.
     */
    public function generateForLeave(LeaveRequest $request): ApprovalWorkflow
    {
        return DB::transaction(function () use ($request) {
            $employee = $request->employee;
            $company = $request->company;
            $startDate = $request->start_date;

            // 1. CEO Leave: Auto-approved immediately
            if ($employee->is_ceo) {
                return $this->createAutoApprovedWorkflow($request, 'CEO leave is automatically approved.');
            }

            $stepsData = [];

            // 2. HOD Leave: HOD -> CEO
            if ($employee->is_hod) {
                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }
            // 3. Team Leader Leave: TL -> HOD -> (Project Engineer if project) -> CEO
            elseif ($employee->is_team_leader) {
                $hodId = $employee->department?->hod_employee_id;
                if ($hodId && $hodId !== $employee->id) {
                    $stepsData[] = [
                        'role_type' => 'hod',
                        'approver_employee_id' => $hodId,
                    ];
                }

                if ($request->project_id && $request->project?->project_engineer_id) {
                    $peId = $request->project->project_engineer_id;
                    if ($peId !== $employee->id) {
                        $stepsData[] = [
                            'role_type' => 'project_engineer',
                            'approver_employee_id' => $peId,
                        ];
                    }
                }

                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }
            // 4. Regular Employee Leave: Employee -> Team Leader -> (Project Engineer if project) -> CEO
            else {
                $tlId = $employee->team_leader_id ?? $employee->manager_id;
                if ($tlId && $tlId !== $employee->id) {
                    $stepsData[] = [
                        'role_type' => 'team_leader',
                        'approver_employee_id' => $tlId,
                    ];
                }

                if ($request->project_id && $request->project?->project_engineer_id) {
                    $peId = $request->project->project_engineer_id;
                    if ($peId !== $employee->id && $peId !== $tlId) {
                        $stepsData[] = [
                            'role_type' => 'project_engineer',
                            'approver_employee_id' => $peId,
                        ];
                    }
                }

                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }

            return $this->buildWorkflow($request, $stepsData, $startDate);
        });
    }

    /**
     * Generate dynamic approval workflow for a PermissionRequest (Project Engineer excluded).
     */
    public function generateForPermission(PermissionRequest $request): ApprovalWorkflow
    {
        return DB::transaction(function () use ($request) {
            $employee = $request->employee;
            $company = $request->company;
            $date = $request->date;

            // 1. CEO Permission: Auto-approved immediately
            if ($employee->is_ceo) {
                return $this->createAutoApprovedWorkflow($request, 'CEO permission is automatically approved.');
            }

            $stepsData = [];

            // 2. HOD: HOD -> CEO
            if ($employee->is_hod) {
                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }
            // 3. Team Leader: TL -> HOD -> CEO
            elseif ($employee->is_team_leader) {
                $hodId = $employee->department?->hod_employee_id;
                if ($hodId && $hodId !== $employee->id) {
                    $stepsData[] = [
                        'role_type' => 'hod',
                        'approver_employee_id' => $hodId,
                    ];
                }
                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }
            // 4. Regular Employee: Employee -> Team Leader -> HOD -> CEO
            else {
                $tlId = $employee->team_leader_id ?? $employee->manager_id;
                if ($tlId && $tlId !== $employee->id) {
                    $stepsData[] = [
                        'role_type' => 'team_leader',
                        'approver_employee_id' => $tlId,
                    ];
                }

                $hodId = $employee->department?->hod_employee_id;
                if ($hodId && $hodId !== $employee->id && $hodId !== $tlId) {
                    $stepsData[] = [
                        'role_type' => 'hod',
                        'approver_employee_id' => $hodId,
                    ];
                }

                $stepsData[] = [
                    'role_type' => 'ceo',
                    'approver_employee_id' => $company->ceo_employee_id,
                ];
            }

            return $this->buildWorkflow($request, $stepsData, $date);
        });
    }

    /**
     * Build workflow records and process absent approver skipping.
     */
    protected function buildWorkflow(
        LeaveRequest|PermissionRequest $request,
        array $stepsData,
        Carbon|string $targetDate
    ): ApprovalWorkflow {
        $workflow = ApprovalWorkflow::create([
            'company_id' => $request->company_id,
            'approvable_type' => get_class($request),
            'approvable_id' => $request->id,
            'status' => 'pending',
            'current_step_order' => 1,
            'total_steps' => count($stepsData),
        ]);

        $order = 1;
        foreach ($stepsData as $data) {
            ApprovalWorkflowStep::create([
                'company_id' => $request->company_id,
                'approval_workflow_id' => $workflow->id,
                'step_order' => $order++,
                'role_type' => $data['role_type'],
                'approver_employee_id' => $data['approver_employee_id'] ?? null,
                'status' => 'pending',
            ]);
        }

        // Evaluate absent approver skipping starting from step 1
        $this->evaluateStepAdvancement($workflow, $targetDate);

        return $workflow->fresh(['steps']);
    }

    /**
     * Auto-advance skipped steps if the assigned approver is absent on the date.
     */
    public function evaluateStepAdvancement(ApprovalWorkflow $workflow, Carbon|string $targetDate): void
    {
        $currentStep = $workflow->currentStep();

        while ($currentStep && $currentStep->status === 'pending') {
            $approver = $currentStep->approverEmployee;

            // If approver is missing or is absent on approved leave, skip this step
            if ($approver && $approver->isAbsentOn($targetDate)) {
                $currentStep->status = 'skipped';
                $currentStep->skipped_reason = 'Approver absent on approved leave';
                $currentStep->actioned_at = now();
                $currentStep->save();

                $workflow->current_step_order++;
                $workflow->save();
                $currentStep = $workflow->currentStep();
            } else {
                break;
            }
        }

        // If all steps were skipped/completed, approve the entire request
        if ($workflow->current_step_order > $workflow->total_steps) {
            $workflow->status = 'approved';
            $workflow->save();
            $workflow->approvable->update(['status' => 'approved']);
        }
    }

    /**
     * Create an automatically approved workflow for CEO submissions.
     */
    protected function createAutoApprovedWorkflow(
        LeaveRequest|PermissionRequest $request,
        string $reason
    ): ApprovalWorkflow {
        $workflow = ApprovalWorkflow::create([
            'company_id' => $request->company_id,
            'approvable_type' => get_class($request),
            'approvable_id' => $request->id,
            'status' => 'approved',
            'current_step_order' => 1,
            'total_steps' => 1,
        ]);

        ApprovalWorkflowStep::create([
            'company_id' => $request->company_id,
            'approval_workflow_id' => $workflow->id,
            'step_order' => 1,
            'role_type' => 'ceo',
            'approver_employee_id' => $request->employee_id,
            'status' => 'approved',
            'actioned_at' => now(),
            'comments' => $reason,
        ]);

        $request->update(['status' => 'approved']);

        // Notify HR of CEO action
        $this->notificationService->notifyHrOfCeoSubmission($request);

        return $workflow;
    }
}
