<?php

use App\Enums\UserRole;
use App\Models\CasualLeaveTracker;
use App\Models\Company;
use App\Models\CompanyWorkSchedule;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\SickLeaveTracker;
use App\Models\User;
use App\Services\Leave\SickLeaveService;
use App\Services\Leave\YearEndProcessor;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    app(TenantContext::class)->set($this->company);

    CompanyWorkSchedule::create([
        'company_id' => $this->company->id,
        'name' => 'Standard',
        'standard_daily_hours' => 9.00,
        'work_start_time' => '08:30:00',
        'work_end_time' => '17:30:00',
        'is_friday_weekend' => true,
        'is_saturday_weekend' => true,
        'is_active' => true,
    ]);

    $this->annualType = LeaveType::create([
        'company_id' => $this->company->id,
        'name' => 'Annual Leave',
        'code' => 'ANNUAL',
        'unit' => 'days',
        'requires_approval' => true,
        'deducts_from_annual_balance' => false,
        'is_paid' => true,
        'is_active' => true,
    ]);

    $this->casualType = LeaveType::create([
        'company_id' => $this->company->id,
        'name' => 'Casual Leave',
        'code' => 'CASUAL',
        'unit' => 'days',
        'requires_approval' => true,
        'deducts_from_annual_balance' => true,
        'is_paid' => true,
        'is_active' => true,
        'max_days_per_year' => 7.00,
        'max_consecutive_days' => 2,
    ]);

    $this->sickType = LeaveType::create([
        'company_id' => $this->company->id,
        'name' => 'Sick Leave',
        'code' => 'SICK',
        'unit' => 'days',
        'requires_approval' => true,
        'deducts_from_annual_balance' => false,
        'is_paid' => true,
        'is_active' => true,
    ]);
});

afterEach(function () {
    app(TenantContext::class)->clear();
});

// ─── Casual Leave Carryover Rule ──────────────────────────────────────────────

test('year-end process resets casual leave to 7 days with no carryover to annual leave', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(3)->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    $year = (int) date('Y');

    CasualLeaveTracker::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'year' => $year,
        'max_quota_days' => 7.00,
        'used_quota_days' => 5.00,
        'pending_quota_days' => 0.00,
    ]);

    $annualAllocation = LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => $year,
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);

    // Run year-end processing via the full process() method
    $yearEnd = app(YearEndProcessor::class);
    $yearEnd->process($this->company, $year);

    // Next-year casual tracker should be reset to 7 days, zero used
    $newTracker = CasualLeaveTracker::where('employee_id', $employee->id)
        ->where('year', $year + 1)
        ->first();

    expect($newTracker)->not->toBeNull();
    expect((float) $newTracker->max_quota_days)->toBe(7.00);
    expect((float) $newTracker->used_quota_days)->toBe(0.00);

    // Next year's annual allocation — carried_over_days set from remaining annual (21 unused)
    $nextAnnual = LeaveAllocation::where('employee_id', $employee->id)
        ->where('year', $year + 1)
        ->where('leave_type_id', $this->annualType->id)
        ->first();

    // The casual 5 used days were NOT added to carried_over_days
    // Carried over = 21 unused annual days (5 casual used does NOT contribute)
    expect($nextAnnual)->not->toBeNull();
    expect((float) $nextAnnual->carried_over_days)->toBe(21.00); // annual only
});

// ─── SickLeaveService — 0.25 penalty from day 8 ──────────────────────────────

test('sick leave under 7 days threshold incurs zero penalty', function () {
    $sickLeaveService = app(SickLeaveService::class);
    $employee = Employee::factory()->create();

    $annualAlloc = LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);

    // Record 5 sick days — below 7 threshold
    $sickLeaveService->recordApprovedSickDays($employee, (int) date('Y'), 5.0);

    $tracker = SickLeaveTracker::where('employee_id', $employee->id)->first();
    $annualAlloc->refresh();

    expect((float) $tracker->used_sick_days)->toBe(5.00);
    expect((float) $annualAlloc->used_days)->toBe(0.00); // no penalty deducted
});

test('sick leave beyond 7-day threshold applies 0.25-day penalty per excess day', function () {
    $sickLeaveService = app(SickLeaveService::class);
    $employee = Employee::factory()->create();

    $annualAlloc = LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);

    // Record 7 days at threshold, then 2 more excess
    $sickLeaveService->recordApprovedSickDays($employee, (int) date('Y'), 7.0);
    $sickLeaveService->recordApprovedSickDays($employee, (int) date('Y'), 2.0);

    $annualAlloc->refresh();

    // 2 excess days × 0.25 = 0.50 penalty deducted from annual
    expect((float) $annualAlloc->used_days)->toBe(0.50);
});

test('sick leave does NOT block requests beyond 7 days — only applies penalty', function () {
    $user = User::factory()->create(['role' => UserRole::Owner]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $token = $user->createToken('test')->plainTextToken;

    SickLeaveTracker::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'year' => (int) date('Y'),
        'policy_threshold_days' => 7.00,
        'used_sick_days' => 8.00, // already beyond threshold
        'excess_sick_days' => 1.00,
        'total_penalty_deduction_days' => 0.25,
    ]);

    LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.25,
        'pending_days' => 0.00,
    ]);

    app(TenantContext::class)->clear();

    // Submit another sick leave request — should NOT be blocked
    $nextMonday = Carbon::now()->next(Carbon::MONDAY);
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->sickType->id,
            'start_date' => $nextMonday->toDateString(),
            'end_date' => $nextMonday->toDateString(),
            'reason' => 'Continuing illness',
        ]);

    // 201 = accepted, not blocked
    $response->assertStatus(201);
});

// ─── Casual Leave Quota Enforcement ──────────────────────────────────────────

test('casual leave blocks request when 7-day quota is exhausted', function () {
    $user = User::factory()->create(['role' => UserRole::Owner]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $token = $user->createToken('test')->plainTextToken;

    CasualLeaveTracker::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'year' => (int) date('Y'),
        'max_quota_days' => 7.00,
        'used_quota_days' => 7.00, // quota exhausted
        'pending_quota_days' => 0.00,
    ]);

    LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);

    app(TenantContext::class)->clear();

    $nextMonday = Carbon::now()->next(Carbon::MONDAY);
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->casualType->id,
            'start_date' => $nextMonday->toDateString(),
            'end_date' => $nextMonday->toDateString(),
            'reason' => 'Personal errand',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrorFor('leave_type_id');
});

test('casual leave of more than 2 consecutive working days is rejected', function () {
    $user = User::factory()->create(['role' => UserRole::Owner]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $token = $user->createToken('test')->plainTextToken;

    CasualLeaveTracker::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'year' => (int) date('Y'),
        'max_quota_days' => 7.00,
        'used_quota_days' => 0.00,
        'pending_quota_days' => 0.00,
    ]);

    LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);

    app(TenantContext::class)->clear();

    // Mon to Wed = 3 working days (exceeds max 2 consecutive)
    $monday = Carbon::now()->next(Carbon::MONDAY);
    $wednesday = (clone $monday)->addDays(2);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->casualType->id,
            'start_date' => $monday->toDateString(),
            'end_date' => $wednesday->toDateString(),
            'reason' => 'Extended casual',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrorFor('end_date');
});
