<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\AnnualLeaveEntitlementCalculator;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->calculator = app(AnnualLeaveEntitlementCalculator::class);
    $this->company = Company::factory()->create();
    app(TenantContext::class)->set($this->company);
});

afterEach(function () {
    app(TenantContext::class)->clear();
});

// ─── Egyptian Labor Law No. 14 of 2025 — Statutory Tiers ─────────────────────

test('first year employee (<1 year tenure) gets 15 days statutory entitlement', function () {
    // Hired mid-year; tenure on Jan 1st = 0 (< 1 year)
    $employee = Employee::factory()->create([
        'hire_date' => now()->subMonths(6)->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['statutory_entitlement_days'])->toBe(15.00);
});

test('standard employee (1-9 years tenure, age <50) gets 21 days', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(3)->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['statutory_entitlement_days'])->toBe(21.00);
});

test('senior employee age 50+ on January 1st gets 30 days', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(5)->toDateString(),
        'date_of_birth' => Carbon::create((int) date('Y') - 51, 6, 15)->toDateString(), // was 51 on Jan 1
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['statutory_entitlement_days'])->toBe(30.00);
});

test('employee with 10+ insurance years gets 30 days', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(5)->toDateString(),
        'date_of_birth' => '1985-01-01',
        'insurance_years' => 10.5,
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['statutory_entitlement_days'])->toBe(30.00);
});

test('employee with disability gets 45 days statutory entitlement', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(3)->toDateString(),
        'date_of_birth' => '1990-01-01',
        'is_disabled' => true,
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['statutory_entitlement_days'])->toBe(45.00);
});

// ─── Pro-Rata Calculation ─────────────────────────────────────────────────────

test('employee hired mid-year gets pro-rata allocation for current year', function () {
    $year = (int) date('Y');
    $hireDate = Carbon::create($year, 7, 1); // Hired July 1st

    $employee = Employee::factory()->create([
        'hire_date' => $hireDate->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    $calc = $this->calculator->calculate($employee, $year);

    // July 1 to Dec 31 = 184 days in non-leap year (or 184/366 in leap)
    expect($calc['pro_rata_factor'])->toBeLessThan(1.0);
    expect($calc['allocated_days'])->toBeLessThan(15.0); // First year 15 days prorated
    expect($calc['allocated_days'])->toBeGreaterThan(0.0);
});

test('employee hired before the year gets full pro-rata factor of 1.0', function () {
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(2)->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    $calc = $this->calculator->calculate($employee, (int) date('Y'));

    expect($calc['pro_rata_factor'])->toBe(1.0000);
    expect($calc['allocated_days'])->toBe(21.00);
});

// ─── Statutory Entitlement API Endpoint ──────────────────────────────────────

test('owner can call calculate-statutory endpoint to generate leave allocation', function () {
    $user = User::factory()->create(['role' => UserRole::Owner]);
    $token = $user->createToken('test')->plainTextToken;

    // Employee must have insurance_years column; create with hire_date
    $employee = Employee::factory()->create([
        'hire_date' => now()->subYears(3)->toDateString(),
        'date_of_birth' => '1990-01-01',
    ]);

    // Create an ANNUAL leave type for this company
    LeaveType::create([
        'company_id' => $this->company->id,
        'name' => 'Annual Leave',
        'code' => 'ANNUAL',
        'unit' => 'days',
        'requires_approval' => true,
        'deducts_from_annual_balance' => false,
        'is_paid' => true,
        'is_active' => true,
    ]);
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/leave-allocations/calculate-statutory', [
            'employee_id' => $employee->id,
            'year' => (int) date('Y'),
            'persist' => true,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('statutory_law', 'Egyptian Labor Law No. 14 of 2025')
        ->assertJsonStructure([
            'calculation' => ['statutory_entitlement_days', 'pro_rata_factor', 'allocated_days'],
            'allocation' => ['id', 'allocated_days'],
        ]);
});
