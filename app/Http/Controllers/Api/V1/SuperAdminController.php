<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Models\Company;
use App\Services\Workflow\ApprovalWorkflowTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $workflow = ApprovalWorkflow::withoutGlobalScopes()->findOrFail($validated['workflow_id']);
        $decision = $validated['decision'];
        $reason = $validated['reason'] ?? 'Super Admin emergency override';

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

        return response()->json([
            'message' => "Workflow {$workflow->id} successfully overridden to {$decision}.",
            'workflow' => $workflow->fresh(['steps']),
        ]);
    }
}
