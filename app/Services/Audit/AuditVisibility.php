<?php

namespace App\Services\Audit;

/**
 * Phase 3.3: which audit events an operational auditor (audit_logs.view_operational,
 * e.g. HR) may see. An explicit allowlist: every action not listed here, including all
 * RBAC, protected-account, platform and unknown actions, is 'restricted' and visible only
 * to holders of audit_logs.view (System Super Admin). Fail closed.
 */
final class AuditVisibility
{
    public const OPERATIONAL = 'operational';

    public const RESTRICTED = 'restricted';

    /** @var list<string> */
    public const ACTIONS = [
        'create_holiday', 'delete_holiday', 'update_work_schedule', 'create_leave_type', 'update_leave_type',
        'create_project', 'update_project', 'delete_project', 'assign_project_employees',
        'create_permission_request', 'approve_permission_step', 'reject_permission_request', 'cancel_permission_request',
        'create_leave_request', 'approve_leave_step', 'reject_leave_request', 'cancel_leave_request',
        'accrue_compensatory_time', 'convert_compensatory_to_leave', 'calculate_statutory_allocation', 'store_leave_allocation',
        'employee.created', 'employee.updated', 'employee.deleted', 'employee.terminated', 'employee.reactivated',
        'employee.avatar_changed', 'employee.profile_updated',
        'department.created', 'department.updated', 'department.deleted',
        'job_title.created', 'job_title.updated', 'job_title.deleted',
        'account.created', 'account.linked', 'account.unlinked', 'account.password_reset',
        'account.activated', 'account.deactivated',
    ];

    public static function for(string $action): string
    {
        return in_array($action, self::ACTIONS, true) ? self::OPERATIONAL : self::RESTRICTED;
    }
}
