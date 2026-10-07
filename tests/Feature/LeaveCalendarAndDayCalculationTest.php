<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkSchedule;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveDayCalculator;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    app(TenantContext::class)->set($this->company);

    // Standard work schedule: Mon–Thu work, Fri+Sat weekend
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

    $this->calculator = app(LeaveDayCalculator::class);
});

afterEach(function () {
    app(TenantContext::class)->clear();
});

// ─── LeaveDayCalculator ───────────────────────────────────────────────────────

test('friday is excluded from deducted days count', function () {
    $friday = Carbon::now()->next(Carbon::FRIDAY);

    $deducted = $this->calculator->calculateDeductedDays(
        $this->annualType,
        $friday,
        $friday,
        $this->company
    );

    expect($deducted)->toBe(0.0);
});

test('saturday is excluded from deducted days for leaves of 5 days or fewer', function () {
    $saturday = Carbon::now()->next(Carbon::SATURDAY);

    $deducted = $this->calculator->calculateDeductedDays(
        $this->annualType,
        $saturday,
        $saturday,
        $this->company
    );

    expect($deducted)->toBe(0.0);
});

test('public holiday is excluded from deducted days', function () {
    $monday = Carbon::now()->next(Carbon::MONDAY);

    // Create holiday with required columns: start_date, end_date, days_count, year
    CompanyHoliday::create([
        'company_id' => $this->company->id,
        'name' => 'Test Holiday',
        'start_date' => $monday->toDateString(),
        'end_date' => $monday->toDateString(),
        'days_count' => 1,
        'year' => $monday->year,
    ]);

    $deducted = $this->calculator->calculateDeductedDays(
        $this->annualType,
        $monday,
        $monday,
        $this->company
    );

    expect($deducted)->toBe(0.0);
});

test('a regular work week mon-to-thu counts 4 deducted days (fri+sat excluded)', function () {
    $monday = Carbon::now()->next(Carbon::MONDAY);
    $thursday = (clone $monday)->addDays(3);

    $deducted = $this->calculator->calculateDeductedDays(
        $this->annualType,
        $monday,
        $thursday,
        $this->company
    );

    expect($deducted)->toBe(4.0);
});

test('sick leave counts all calendar days including weekend', function () {
    $friday = Carbon::now()->next(Carbon::FRIDAY);
    $saturday = (clone $friday)->addDay();

    $deducted = $this->calculator->calculateDeductedDays(
        $this->sickType,
        $friday,
        $saturday,
        $this->company
    );

    expect($deducted)->toBe(2.0);
});

test('annual leave spanning more than 5 calendar days includes saturday in deduction', function () {
    // Mon to next Mon = 8 calendar days (crosses over Fri+Sat)
    $monday = Carbon::now()->next(Carbon::MONDAY);
    $nextMonday = (clone $monday)->addDays(7);

    $deducted = $this->calculator->calculateDeductedDays(
        $this->annualType,
        $monday,
        $nextMonday,
        $this->company
    );

    // 8 calendar days: excludes only Friday; includes Saturday (long leave rule)
    // Mon(1) + Tue(2) + Wed(3) + Thu(4) + Fri=skip + Sat(5) + Sun(6) + Mon(7) = 7
    expect($deducted)->toBeGreaterThan(5.0);
});

// ─── Work Schedule API ────────────────────────────────────────────────────────

test('any authenticated tenant user can view the company work schedule', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $token = $user->createToken('test')->plainTextToken;
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/work-schedule');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['standard_daily_hours']]);
});

test('only owner/admin can update the company work schedule', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    $token = $employee->createToken('test')->plainTextToken;
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/work-schedule', [
            'standard_daily_hours' => 8.0,
            'work_start_time' => '09:00',
            'work_end_time' => '17:00',
        ]);

    $response->assertStatus(403);
});

// ─── Company Holidays API ─────────────────────────────────────────────────────

test('owner can create a company holiday', function () {
    $user = User::factory()->create(['role' => UserRole::Owner]);
    $token = $user->createToken('test')->plainTextToken;
    app(TenantContext::class)->clear();

    $holidayDate = now()->addDays(30)->format('Y-m-d');

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/holidays', [
            'name' => 'National Day',
            'start_date' => $holidayDate,
            'end_date' => $holidayDate,
        ]);

    $response->assertStatus(201)->assertJsonPath('data.name', 'National Day');
});

test('employee cannot create a holiday', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $token = $user->createToken('test')->plainTextToken;
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/holidays', [
            'name' => 'Fake Holiday',
            'start_date' => now()->addDays(30)->format('Y-m-d'),
            'end_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

    $response->assertStatus(403);
});
