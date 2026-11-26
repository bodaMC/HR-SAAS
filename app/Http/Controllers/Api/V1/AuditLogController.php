<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Services\Audit\AuditVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    /**
     * Server-side visibility: holders of audit_logs.view see every tenant event; operational auditors
     * (HR) see only 'operational' events. The restriction applies BEFORE filters, pagination and
     * counts, so restricted events never leak through totals, search or filters.
     */
    private function scoped(Request $request): Builder
    {
        $q = AuditLog::query();
        if (! $request->user()->can('viewAll', AuditLog::class)) {
            $q->where('visibility', AuditVisibility::OPERATIONAL);
        }

        return $q;
    }

    public function show(Request $request, int $auditLog): AuditLogResource
    {
        $this->authorize('viewAny', AuditLog::class);

        return new AuditLogResource($this->scoped($request)->with('user')->findOrFail($auditLog));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'auditable_type' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $query = $this->scoped($request)->with('user');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', 'like', '%'.$request->string('auditable_type').'%');
        }

        $logs = $query->latest('created_at')->paginate(30);

        return AuditLogResource::collection($logs);
    }
}
