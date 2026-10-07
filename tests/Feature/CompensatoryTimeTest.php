<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompensatoryBalance;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    app(TenantContext::class)->set($this->company);

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

    $this->owner = User::factory()->create(['role' => UserRole::Owner]);
    $this->ownerToken = $this->owner->createToken('test')->plainTextToken;

    $this->employee = Employee::factory()->create([
        'hire_date' => now()->subYears(2)->toDateString(),
    ]);

    $this->balance = CompensatoryBalance::create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'total_earned_hours' => 18.00,
        'used_as_permission_hours' => 0.00,
        'converted_to_leave_hours' => 0.00,
    ]);

    $this->annualAlloc = LeaveAllocation::create([
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->annualType->id,
        'year' => (int) date('Y'),
        'allocated_days' => 21.00,
        'used_days' => 0.00,
        'pending_days' => 0.00,
    ]);
});

afterEach(function () {
    app(TenantContext::class)->clear();
});

// ─── Compensatory Balance ─────────────────────────────────────────────────────

test('owner can view compensatory balance for an employee', function () {
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->ownerToken)
        ->getJson('/api/v1/compensatory-balance?employee_id='.$this->employee->id);

    $response->assertStatus(200)
        ->assertJsonPath('balance.total_earned_hours', 18)
        ->assertJsonPath('balance.available_hours', 18);
});

test('available_hours accessor correctly subtracts used and converted hours', function () {
    $this->balance->used_as_permission_hours = 4.00;
    $this->balance->converted_to_leave_hours = 9.00;
    $this->balance->save();

    $this->balance->refresh();

    // 18 - 4 - 9 = 5 available
    expect($this->balance->available_hours)->toBe(5.0);
});

// ─── Accrue Compensatory Time ─────────────────────────────────────────────────

test('admin can accrue compensatory hours for an employee', function () {
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->ownerToken)
        ->postJson('/api/v1/compensatory-time/accrue', [
            'employee_id' => $this->employee->id,
            'hours' => 4.5,
            'reason' => 'Overtime for project deadline',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.total_earned_hours', 22.5); // 18 + 4.5

    $this->balance->refresh();
    expect((float) $this->balance->total_earned_hours)->toBe(22.5);
});

test('employee cannot accrue their own compensatory time', function () {
    $empUser = User::factory()->create(['role' => UserRole::Employee]);
    $empEmployee = Employee::factory()->create(['user_id' => $empUser->id]);
    $token = $empUser->createToken('test')->plainTextToken;
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/compensatory-time/accrue', [
            'employee_id' => $empEmployee->id,
            'hours' => 3.0,
            'reason' => 'Self-reported overtime',
        ]);

    $response->assertStatus(403);
});

// ─── Convert Compensatory Hours → Annual Leave (Manual only) ─────────────────

test('admin can manually convert 9 compensatory hours to 1 annual leave day', function () {
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->ownerToken)
        ->postJson('/api/v1/compensatory-time/convert', [
            'employee_id' => $this->employee->id,
            'year' => (int) date('Y'),
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'conversion' => ['hours_converted', 'leave_days_added'],
            'balance',
        ])
        ->assertJsonPath('conversion.leave_days_added', 1);

    $this->balance->refresh();
    expect((float) $this->balance->converted_to_leave_hours)->toBe(9.00);
    expect((float) $this->balance->available_hours)->toBe(9.0); // 18 - 9 = 9

    $this->annualAlloc->refresh();
    expect((float) $this->annualAlloc->converted_from_compensatory_days)->toBe(1.00);
});

test('conversion fails when compensatory balance is insufficient', function () {
    // Set balance to zero available hours
    $this->balance->update([
        'total_earned_hours' => 5.00, // less than 9
        'used_as_permission_hours' => 0.00,
        'converted_to_leave_hours' => 0.00,
    ]);
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->ownerToken)
        ->postJson('/api/v1/compensatory-time/convert', [
            'employee_id' => $this->employee->id,
            'year' => (int) date('Y'),
        ]);

    $response->assertStatus(500); // InvalidArgumentException → 500 or custom error
});

test('compensatory conversion is NEVER automatic — only via explicit HR action', function () {
    // This test asserts the design: no automatic conversion should occur.
    // Compensatory balance stays the same without an explicit POST to the convert endpoint.
    $this->balance->refresh();
    expect((float) $this->balance->converted_to_leave_hours)->toBe(0.0);

    $this->annualAlloc->refresh();
    expect((float) $this->annualAlloc->converted_from_compensatory_days)->toBe(0.0);
});
