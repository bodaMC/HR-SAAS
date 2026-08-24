<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cross-tenant route model binding returns 404 for departments, job titles, and employees', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Company A User
    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    app(TenantContext::class)->clear();

    // Company B Resources
    app(TenantContext::class)->set($companyB);
    $deptB = Department::factory()->create(['name' => 'Secret Dept']);
    $jobTitleB = JobTitle::factory()->create(['name' => 'Secret Title']);
    $employeeB = Employee::factory()->create(['first_name' => 'Secret Worker']);
    app(TenantContext::class)->clear();

    // 1. Cross-tenant Department Access -> 404
    $respDept = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/departments/'.$deptB->id);
    $respDept->assertStatus(404);

    $this->app['auth']->forgetGuards();

    // 2. Cross-tenant Job Title Access -> 404
    $respJob = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/job-titles/'.$jobTitleB->id);
    $respJob->assertStatus(404);

    $this->app['auth']->forgetGuards();

    // 3. Cross-tenant Employee Access -> 404
    $respEmp = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/employees/'.$employeeB->id);
    $respEmp->assertStatus(404);
});

test('employee company_id and uuid cannot be spoofed or mutated via API or Eloquent', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;

    // 1. Attempt creating employee with spoofed company_id and uuid
    $response = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-SPOOF',
            'first_name' => 'Spoof',
            'last_name' => 'Test',
            'email' => 'spoof@example.com',
            'company_id' => $companyB->id,
            'uuid' => '00000000-0000-0000-0000-000000000000',
        ]);

    $response->assertStatus(201);
    $employee = Employee::where('employee_number', 'EMP-SPOOF')->first();

    // Must belong to Company A and have generated UUID
    expect($employee->company_id)->toBe($companyA->id)
        ->and($employee->uuid)->not->toBe('00000000-0000-0000-0000-000000000000');

    // 2. Direct model update of company_id throws InvalidArgumentException
    expect(fn () => $employee->update(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');

    // 3. Bulk update of company_id throws InvalidArgumentException
    expect(fn () => Employee::where('id', $employee->id)->update(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');
});

test('creating department, job title, or employee without tenant context throws RuntimeException', function () {
    app(TenantContext::class)->clear();

    expect(fn () => Department::create(['name' => 'Orphan Dept']))
        ->toThrow(RuntimeException::class, 'Tenant context is missing. Cannot create tenant-scoped model.');

    expect(fn () => JobTitle::create(['name' => 'Orphan Title']))
        ->toThrow(RuntimeException::class, 'Tenant context is missing. Cannot create tenant-scoped model.');

    expect(fn () => Employee::create([
        'employee_number' => 'EMP-ORPHAN',
        'first_name' => 'Orphan',
        'last_name' => 'Person',
        'email' => 'orphan@example.com',
    ]))->toThrow(RuntimeException::class, 'Tenant context is missing. Cannot create tenant-scoped model.');
});
