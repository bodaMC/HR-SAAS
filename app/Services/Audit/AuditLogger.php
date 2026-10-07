<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log an administrative or business action to the audit logs table.
     *
     * Note: Super Admin operations are exempt from business audit logging.
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

        // Single protected Super Admin is exempt from audit logging
        if ($user && $user->isSuperAdmin()) {
            return null;
        }

        $companyId = app(TenantContext::class)->id() ?? $user?->company_id ?? $auditable?->company_id;

        if (! $companyId) {
            return null;
        }

        return AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $user?->id,
            'auditable_type' => $auditable ? get_class($auditable) : 'System',
            'auditable_id' => $auditable?->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
