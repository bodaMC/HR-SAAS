<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log an administrative or business action to the audit logs table.
     *
     * Super Admin operations are audited under the affected tenant (Phase 3.2).
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null
    ): ?AuditLog {
        $user = $user ?? auth()->user();

        // Phase 3.2: Super Admin operations are audited too, under the tenant that
        // is actually affected (the audited record's company), never skipped.
        $companyId = $auditable?->company_id ?? app(TenantContext::class)->id() ?? $user?->company_id;

        if (! $companyId) {
            return null;
        }

        // AuditLog is tenant-scoped: write it under the affected tenant, then restore
        // whatever context the caller had (the Super Admin routes have none).
        $context = app(TenantContext::class);
        $previous = $context->get();
        if ($context->id() !== (int) $companyId) {
            $context->set(Company::withoutGlobalScopes()->findOrFail($companyId));
        }

        try {
            return AuditLog::create([
                'user_id' => $user?->id,
                'auditable_type' => $auditable ? get_class($auditable) : 'System',
                'auditable_id' => $auditable?->id,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        } finally {
            $context->set($previous);
        }
    }
}
