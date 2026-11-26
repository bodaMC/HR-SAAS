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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // Phase 3.3: rate limit for password-management endpoints (per authenticated user, else IP).
        // Password-management limits are keyed by the ACCOUNT (resolved through the sanctum guard), not by the bearer
        // token, so minting or holding several tokens for one account does not multiply the allowance. A second
        // per-IP limit bounds one client rotating accounts. Unauthenticated callers fall back to the IP alone.
        $tooMany = fn () => response()->json(['message' => __('accounts.too_many_attempts'), 'code' => 'TOO_MANY_ATTEMPTS'], 429);
        $limits = function (string $name, int $perAccount, int $perIp) use ($tooMany) {
            return function (Request $r) use ($name, $perAccount, $perIp, $tooMany) {
                $uid = $r->user('sanctum')?->getAuthIdentifier();
                $out = [Limit::perMinute($perIp)->by($name.'-ip|'.$r->ip())->response($tooMany)];
                if ($uid !== null) {
                    $out[] = Limit::perMinute($perAccount)->by($name.'-acct|'.$uid)->response($tooMany);
                }

                return $out;
            };
        };
        RateLimiter::for('password', $limits('pw', 10, 60));
        RateLimiter::for('account-admin', $limits('aa', 40, 200));

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
