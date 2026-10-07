<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CompanyHolidayController;
use App\Http\Controllers\Api\V1\CompanyWorkScheduleController;
use App\Http\Controllers\Api\V1\CompensatoryTimeController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\JobTitleController;
use App\Http\Controllers\Api\V1\LeaveAllocationController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionRequestController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ─── Public Auth Routes ───────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register-company', [AuthController::class, 'registerCompany']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // ─── Tenant-Scoped Routes ─────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        // Phase 7 — Core HR Entities
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('job-titles', JobTitleController::class);
        Route::apiResource('employees', EmployeeController::class);

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

        // Phase 8 — Audit Logs (Owner/Admin only)
        Route::get('audit-logs', [AuditLogController::class, 'index']);
    });

    // ─── Super Admin Routes (system-level, no tenant middleware) ─────────
    Route::middleware(['auth:sanctum'])->prefix('super-admin')->group(function () {
        Route::get('tenants', [SuperAdminController::class, 'listTenants']);
        Route::post('override-workflow', [SuperAdminController::class, 'overrideWorkflow']);
    });
});
