<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkSchedule;
use App\Models\CompensatoryBalance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Policies\AuditLogPolicy;
use App\Policies\CompanyHolidayPolicy;
use App\Policies\CompanyWorkSchedulePolicy;
use App\Policies\CompensatoryTimePolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\JobTitlePolicy;
use App\Policies\LeaveAllocationPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\LeaveTypePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PermissionRequestPolicy;
use App\Policies\ProjectPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, function () {
            return new TenantContext;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 7 — HR Core Policies
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(JobTitle::class, JobTitlePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);

        // Phase 8 — Leave Management Policies
        Gate::policy(LeaveType::class, LeaveTypePolicy::class);
        Gate::policy(LeaveAllocation::class, LeaveAllocationPolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);

        // Phase 8 — Permission Request Policy
        Gate::policy(PermissionRequest::class, PermissionRequestPolicy::class);

        // Phase 8 — Compensatory Time Policy
        Gate::policy(CompensatoryBalance::class, CompensatoryTimePolicy::class);

        // Phase 8 — Project Policy
        Gate::policy(Project::class, ProjectPolicy::class);

        // Phase 8 — Schedule & Holiday Policies
        Gate::policy(CompanyWorkSchedule::class, CompanyWorkSchedulePolicy::class);
        Gate::policy(CompanyHoliday::class, CompanyHolidayPolicy::class);

        // Phase 8 — Notification & Audit Log Policies
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
    }
}
