<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Schedules\UpdateCompanyWorkScheduleRequest;
use App\Http\Resources\Api\V1\CompanyWorkScheduleResource;
use App\Models\CompanyWorkSchedule;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class CompanyWorkScheduleController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    public function show(Request $request): CompanyWorkScheduleResource
    {
        $tenantId = app(TenantContext::class)->id();

        $schedule = CompanyWorkSchedule::firstOrCreate(
            ['company_id' => $tenantId],
            [
                'standard_daily_hours' => 9.00,
                'work_start_time' => '08:30:00',
                'work_end_time' => '17:30:00',
                'is_friday_weekend' => true,
                'is_saturday_weekend' => true,
                'implicit_approval_time' => '17:30:00',
            ]
        );

        return new CompanyWorkScheduleResource($schedule);
    }

    public function update(UpdateCompanyWorkScheduleRequest $request): CompanyWorkScheduleResource
    {
        $this->authorize('update', CompanyWorkSchedule::class);

        $tenantId = app(TenantContext::class)->id();

        $schedule = CompanyWorkSchedule::firstOrCreate(
            ['company_id' => $tenantId],
            [
                'standard_daily_hours' => 9.00,
                'work_start_time' => '08:30:00',
                'work_end_time' => '17:30:00',
                'is_friday_weekend' => true,
                'is_saturday_weekend' => true,
                'implicit_approval_time' => '17:30:00',
            ]
        );

        $old = $schedule->toArray();
        $schedule->update($request->validated());
        $this->auditLogger->log('update_work_schedule', $schedule, $old, $schedule->fresh()->toArray());

        return new CompanyWorkScheduleResource($schedule);
    }
}
