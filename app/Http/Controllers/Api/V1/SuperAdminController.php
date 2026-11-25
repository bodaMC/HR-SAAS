<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Models\Company;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\ApprovalWorkflowTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminController extends Controller
{
    public function __construct(
        protected ApprovalWorkflowTransitionService $transitionService
    ) {}

    /**
     * Ensure current user is the protected system Super Admin.
     */
    protected function ensureSuperAdmin(Request $request): void
    {
        if (! $request->user()?->isSuperAdmin()) {
            abort(Response::HTTP_FORBIDDEN, 'Access restricted to system Super Admin.');
        }
    }

    /**
     * List all companies across the system.
     */
    public function listTenants(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $companies = Company::withoutGlobalScopes()
            ->withCount(['employees', 'users', 'departments'])
            ->get();

        return response()->json([
            'super_admin' => true,
            'companies' => $companies,
        ]);
    }

    /**
     * Emergency administrative override for an approval workflow.
     */
    public function overrideWorkflow(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'workflow_id' => ['required', 'integer'],
            'decision' => ['required', 'string', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:15', 'max:500'],
        ]);

        $workflow = ApprovalWorkflow::withoutGlobalScopes()->findOrFail($validated['workflow_id']);
        $decision = $validated['decision'];
        $statusBefore = $workflow->status;
        $reason = trim($validated['reason']);
        if (mb_strlen($reason) < 15) {
            abort(422, 'A meaningful reason is required.');
        }

        // Mutation and audit record commit or roll back together.
        DB::transaction(function () use ($workflow, $decision, $reason, $statusBefore, $request) {
            if ($decision === 'approve') {
                // Mark all pending steps approved
                $workflow->steps()->where('status', 'pending')->update([
                    'status' => 'approved',
                    'actioned_by_user_id' => $request->user()->id,
                    'actioned_at' => now(),
                    'comments' => $reason,
                ]);

                $workflow->status = 'approved';
                $workflow->current_step_order = $workflow->total_steps + 1;
                $workflow->save();

                $this->transitionService->finalizeRequestApproval($workflow->approvable, $request->user());
            } else {
                $workflow->steps()->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'actioned_by_user_id' => $request->user()->id,
                    'actioned_at' => now(),
                    'comments' => $reason,
                ]);

                $workflow->status = 'rejected';
                $workflow->save();

                $approvable = $workflow->approvable;
                $approvable->status = 'rejected';
                $approvable->final_actioned_by_user_id = $request->user()->id;
                $approvable->final_actioned_at = now();
                $approvable->rejection_reason = $reason;
                $approvable->save();

                $this->transitionService->revertPendingBalances($approvable);
            }

            $entry = app(AuditLogger::class)->log(
                'super_admin.workflow_override',
                $workflow,
                ['status' => $statusBefore],
                ['status' => $workflow->status, 'decision' => $decision, 'reason' => $reason, 'company_id' => $workflow->company_id],
                $request->user()
            );
            if (! $entry) {
                // An exceptional override without its audit record must never persist.
                throw new \RuntimeException('Audit record could not be written; override rolled back.');
            }
        });

        return response()->json([
            'message' => "Workflow {$workflow->id} successfully overridden to {$decision}.",
            'workflow' => $workflow->fresh(['steps']),
        ]);
    }
}
