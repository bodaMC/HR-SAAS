<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaveTypeResource;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LeaveTypeController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $leaveTypes = LeaveType::where('is_active', true)->get();

        return LeaveTypeResource::collection($leaveTypes);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LeaveType::class);

        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('leave_types', 'code')->where('company_id', $tenantId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_approval' => ['nullable', 'boolean'],
            'deducts_from_annual_balance' => ['nullable', 'boolean'],
            'is_paid' => ['nullable', 'boolean'],
            'max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1'],
            'requires_attachment' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $leaveType = LeaveType::create($validated);
        $this->auditLogger->log('create_leave_type', $leaveType, null, $leaveType->toArray());

        return (new LeaveTypeResource($leaveType))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($leaveType);
    }

    public function update(Request $request, LeaveType $leaveType): LeaveTypeResource
    {
        $this->authorize('update', $leaveType);

        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('leave_types', 'code')
                    ->where('company_id', $tenantId)
                    ->ignore($leaveType->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'requires_approval' => ['nullable', 'boolean'],
            'deducts_from_annual_balance' => ['nullable', 'boolean'],
            'is_paid' => ['nullable', 'boolean'],
            'max_days_per_year' => ['nullable', 'numeric', 'min:0'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1'],
            'requires_attachment' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $old = $leaveType->toArray();
        $leaveType->update($validated);
        $this->auditLogger->log('update_leave_type', $leaveType, $old, $leaveType->fresh()->toArray());

        return new LeaveTypeResource($leaveType);
    }
}
