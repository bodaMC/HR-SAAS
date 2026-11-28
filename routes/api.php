<?php

use App\Http\Controllers\Api\V1\Access\AccessUserController;
use App\Http\Controllers\Api\V1\Access\RoleController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CompanyHolidayController;
use App\Http\Controllers\Api\V1\CompanyWorkScheduleController;
use App\Http\Controllers\Api\V1\CompensatoryTimeController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EligibilityController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\JobTitleController;
use App\Http\Controllers\Api\V1\LeaveAllocationController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PermissionRequestController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ─── Public Auth Routes ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        // Phase 3.3: unrestricted public company registration was removed. Companies are provisioned by the
        // trusted operator command (php artisan tenant:provision).
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/change-password', [ProfileController::class, 'changePassword'])->middleware('throttle:password')->name('auth.change-password');
        });
    });

    // ─── Tenant-Scoped Routes ─────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        // Phase 7 — Core HR Entities
        Route::apiResource('departments', DepartmentController::class);
        Route::get('job-titles/eligible', [EligibilityController::class, 'jobTitlesFor']);
        Route::get('job-titles/compatibility-report', [EligibilityController::class, 'jobTitleReport']);
        Route::apiResource('job-titles', JobTitleController::class);
        Route::get('employees/manager-candidates', [EligibilityController::class, 'managerCandidates']);
        Route::get('employees/manager-compatibility-report', [EligibilityController::class, 'managerReport']);
        Route::get('employees/{employee}/manager-scope', [EligibilityController::class, 'showScope'])->whereNumber('employee');
        Route::put('employees/{employee}/manager-scope', [EligibilityController::class, 'setScope'])->whereNumber('employee')->middleware('throttle:account-admin');
        Route::apiResource('employees', EmployeeController::class);

        // Phase 3.3 — employee lifecycle and login-account lifecycle
        Route::post('employees/{employee}/terminate', [EmployeeController::class, 'terminate'])->whereNumber('employee');
        Route::post('employees/{employee}/reactivate', [EmployeeController::class, 'reactivate'])->whereNumber('employee');
        Route::get('employees/{employee}/picture', [ProfileController::class, 'employeePicture'])->whereNumber('employee');
        Route::prefix('employees/{employee}/account')->whereNumber('employee')->group(function () {
            Route::get('/', [AccountController::class, 'show']);
            Route::post('/', [AccountController::class, 'store'])->middleware('throttle:account-admin');
            Route::get('link-candidates', [AccountController::class, 'linkCandidates'])->middleware('throttle:account-admin');
            Route::post('link', [AccountController::class, 'link'])->middleware('throttle:account-admin');
            Route::post('unlink', [AccountController::class, 'unlink'])->middleware('throttle:account-admin');
            Route::post('reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:account-admin');
            Route::post('deactivate', [AccountController::class, 'deactivate'])->middleware('throttle:account-admin');
            Route::post('reactivate', [AccountController::class, 'reactivate'])->middleware('throttle:account-admin');
        });

        // Phase 3.4 — organizational structure (reads: any current employee; writes: explicit permissions)
        Route::prefix('organization')->group(function () {
            Route::get('chart', [OrganizationController::class, 'chart']);
            Route::get('people/{employee}', [OrganizationController::class, 'person'])->whereNumber('employee');
            Route::get('employees/{employee}/picture', [OrganizationController::class, 'picture'])->whereNumber('employee');
            Route::get('departments/{department}/members', [OrganizationController::class, 'departmentMembers'])->whereNumber('department');
            Route::get('eligible-employees', [OrganizationController::class, 'eligibleEmployees']);
            Route::get('unassigned', [OrganizationController::class, 'unassigned']);
            Route::get('people-without-position', [OrganizationController::class, 'peopleWithoutPosition']);
            Route::get('positions', [OrganizationController::class, 'positions']);
            Route::post('positions', [OrganizationController::class, 'store'])->middleware('throttle:account-admin');
            Route::get('positions/{position}', [OrganizationController::class, 'show'])->whereNumber('position');
            Route::put('positions/{position}', [OrganizationController::class, 'update'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::get('positions/{position}/history', [OrganizationController::class, 'history'])->whereNumber('position');
            Route::get('positions/{position}/removal-preview', [OrganizationController::class, 'removalPreview'])->whereNumber('position');
            Route::post('positions/{position}/delete', [OrganizationController::class, 'destroy'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::post('positions/{position}/archive', [OrganizationController::class, 'archive'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::post('positions/{position}/restore', [OrganizationController::class, 'restore'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::post('positions/{position}/reparent', [OrganizationController::class, 'reparent'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::post('positions/{position}/assignments', [OrganizationController::class, 'assign'])->whereNumber('position')->middleware('throttle:account-admin');
            Route::post('assignments/{assignment}/end', [OrganizationController::class, 'endAssignment'])->whereNumber('assignment')->middleware('throttle:account-admin');
            Route::post('assignments/{assignment}/void', [OrganizationController::class, 'voidAssignment'])->whereNumber('assignment')->middleware('throttle:account-admin');
            Route::post('assignments/{assignment}/reinstate', [OrganizationController::class, 'reinstateAssignment'])->whereNumber('assignment')->middleware('throttle:account-admin');
            Route::post('assignments/{assignment}/replace', [OrganizationController::class, 'replaceAssignment'])->whereNumber('assignment')->middleware('throttle:account-admin');
            Route::post('assignments/{assignment}/placement', [OrganizationController::class, 'placement'])->whereNumber('assignment')->middleware('throttle:account-admin');
        });
        Route::get('employees/{employee}/organization', [OrganizationController::class, 'employeeOrganization'])->whereNumber('employee');
        Route::get('me/organization', [OrganizationController::class, 'myOrganization']);

        // Phase 3.3 — My Profile (self-service; phone and picture only)
        Route::prefix('me/profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::post('picture', [ProfileController::class, 'uploadPicture']);
            Route::delete('picture', [ProfileController::class, 'removePicture']);
            Route::get('picture', [ProfileController::class, 'ownPicture']);
        });

        // Phase 8 — Work Schedule & Holidays
        Route::get('work-schedule', [CompanyWorkScheduleController::class, 'show']);
        Route::put('work-schedule', [CompanyWorkScheduleController::class, 'update']);

        Route::get('holidays', [CompanyHolidayController::class, 'index']);
        Route::post('holidays', [CompanyHolidayController::class, 'store']);
        Route::delete('holidays/{holiday}', [CompanyHolidayController::class, 'destroy']);

        // Phase 8 — Projects
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/assign-employees', [ProjectController::class, 'assignEmployees']);

        // Phase 8 — Leave Types
        Route::apiResource('leave-types', LeaveTypeController::class)->except(['destroy']);

        // Phase 8 — Leave Allocations
        Route::get('leave-allocations', [LeaveAllocationController::class, 'index']);
        Route::post('leave-allocations', [LeaveAllocationController::class, 'store']);
        Route::get('leave-allocations/{leaveAllocation}', [LeaveAllocationController::class, 'show']);
        Route::post('leave-allocations/calculate-statutory', [LeaveAllocationController::class, 'calculateStatutory']);

        // Phase 8 — Leave Requests
        Route::get('leave-requests', [LeaveRequestController::class, 'index']);
        Route::post('leave-requests', [LeaveRequestController::class, 'store']);
        Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);

        // Phase 8 — Permission Requests
        Route::get('permission-requests', [PermissionRequestController::class, 'index']);
        Route::post('permission-requests', [PermissionRequestController::class, 'store']);
        Route::get('permission-requests/{permissionRequest}', [PermissionRequestController::class, 'show']);
        Route::post('permission-requests/{permissionRequest}/approve', [PermissionRequestController::class, 'approve']);
        Route::post('permission-requests/{permissionRequest}/reject', [PermissionRequestController::class, 'reject']);
        Route::post('permission-requests/{permissionRequest}/cancel', [PermissionRequestController::class, 'cancel']);
        Route::get('permission-balance', [PermissionRequestController::class, 'getBalance']);

        // Phase 8 — Compensatory Time
        Route::get('compensatory-balance', [CompensatoryTimeController::class, 'getBalance']);
        Route::post('compensatory-time/accrue', [CompensatoryTimeController::class, 'accrue']);
        Route::post('compensatory-time/convert', [CompensatoryTimeController::class, 'convert']);

        // Phase 8 — Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);

        // Phase 3.2 — Roles & Permissions (authorization is permission-based, enforced in the service layer)
        Route::prefix('access')->group(function () {
            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::get('roles', [RoleController::class, 'index']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::get('roles/{role}', [RoleController::class, 'show']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
            Route::get('users', [AccessUserController::class, 'index']);
            Route::get('users/{user}/permissions', [AccessUserController::class, 'permissions'])->whereNumber('user');
            Route::put('users/{user}/roles', [AccessUserController::class, 'syncRoles'])->whereNumber('user');
        });

        // Phase 8 — Audit Logs (audit_logs.view = everything; audit_logs.view_operational = operational events only)
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog');
    });

    // ─── Super Admin Routes (system-level, no tenant middleware) ─────────
    Route::middleware(['auth:sanctum'])->prefix('super-admin')->group(function () {
        Route::get('tenants', [SuperAdminController::class, 'listTenants']);
        Route::post('override-workflow', [SuperAdminController::class, 'overrideWorkflow']);
    });
});
