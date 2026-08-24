<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner and admin can create, read, update, and soft delete an employee', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test-token')->plainTextToken;

    $department = Department::factory()->create(['name' => 'Product']);
    $jobTitle = JobTitle::factory()->create(['name' => 'Product Manager']);
    app(TenantContext::class)->clear();

    // 1. Create Employee
    $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-1001',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@example.com',
            'phone' => '+15551234567',
            'date_of_birth' => '1990-05-15',
            'gender' => 'female',
            'national_id' => 'NAT-998877',
            'hire_date' => '2024-01-10',
            'employment_status' => 'active',
            'department_id' => $department->id,
            'job_title_id' => $jobTitle->id,
        ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'data' => [
                'employee_number' => 'EMP-1001',
                'first_name' => 'Sarah',
                'last_name' => 'Connor',
                'email' => 'sarah@example.com',
                'employment_status' => 'active',
                'department' => [
                    'id' => $department->id,
                    'name' => 'Product',
                ],
                'job_title' => [
                    'id' => $jobTitle->id,
                    'name' => 'Product Manager',
                ],
            ],
        ]);

    $employeeId = $createResponse->json('data.id');
    $employeeUuid = $createResponse->json('data.uuid');
    expect($employeeUuid)->not->toBeNull();

    $this->app['auth']->forgetGuards();

    // 2. Read Employee
    $showResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/employees/'.$employeeId);

    $showResponse->assertStatus(200)
        ->assertJsonPath('data.employee_number', 'EMP-1001');

    $this->app['auth']->forgetGuards();

    // 3. Update Employee
    $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/employees/'.$employeeId, [
            'first_name' => 'Sarah Jane',
            'employment_status' => 'on_leave',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.first_name', 'Sarah Jane')
        ->assertJsonPath('data.employment_status', 'on_leave');

    $this->app['auth']->forgetGuards();

    // 4. Soft Delete Employee
    $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/employees/'.$employeeId);

    $deleteResponse->assertStatus(200);
    expect(Employee::withoutGlobalScopes()->find($employeeId)->deleted_at)->not->toBeNull();
})->with([
    'Owner' => UserRole::Owner,
    'Admin' => UserRole::Admin,
]);

test('employee number and email are uniquely scoped to the company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Company A creates employee with EMP-001 and john@orbit.test
    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    Employee::factory()->create([
        'employee_number' => 'EMP-001',
        'email' => 'john@orbit.test',
    ]);
    app(TenantContext::class)->clear();

    // Attempt duplicate employee_number in Company A -> fails 422
    $dupNumResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-001',
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'other@orbit.test',
        ]);
    $dupNumResponse->assertStatus(422)
        ->assertJsonValidationErrors(['employee_number']);

    $this->app['auth']->forgetGuards();

    // Attempt duplicate email in Company A -> fails 422
    $dupEmailResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-002',
            'first_name' => 'Other',
            'last_name' => 'Person',
            'email' => 'john@orbit.test',
        ]);
    $dupEmailResponse->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->app['auth']->forgetGuards();

    // Company B can use both EMP-001 and john@orbit.test without collision
    app(TenantContext::class)->set($companyB);
    $ownerB = User::factory()->owner()->create();
    $tokenB = $ownerB->createToken('token-b')->plainTextToken;
    app(TenantContext::class)->clear();

    $responseB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@orbit.test',
        ]);

    $responseB->assertStatus(201)
        ->assertJsonPath('data.employee_number', 'EMP-001')
        ->assertJsonPath('data.email', 'john@orbit.test');
});

test('employee cannot link to another company department, job title, or user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    app(TenantContext::class)->clear();

    // Company B resources
    app(TenantContext::class)->set($companyB);
    $deptB = Department::factory()->create();
    $jobTitleB = JobTitle::factory()->create();
    $userB = User::factory()->create();
    app(TenantContext::class)->clear();

    // Company A attempts to link Company B's department -> fails 422
    $respDept = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-900',
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
            'email' => 'cross@example.com',
            'department_id' => $deptB->id,
        ]);
    $respDept->assertStatus(422)
        ->assertJsonValidationErrors(['department_id']);

    $this->app['auth']->forgetGuards();

    // Company A attempts to link Company B's job title -> fails 422
    $respJob = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-900',
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
            'email' => 'cross@example.com',
            'job_title_id' => $jobTitleB->id,
        ]);
    $respJob->assertStatus(422)
        ->assertJsonValidationErrors(['job_title_id']);

    $this->app['auth']->forgetGuards();

    // Company A attempts to link Company B's user -> fails 422
    $respUser = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-900',
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
            'email' => 'cross@example.com',
            'user_id' => $userB->id,
        ]);
    $respUser->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

test('employee number and email remain permanently reserved after soft deletion', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $owner = User::factory()->owner()->create();
    $token = $owner->createToken('token')->plainTextToken;
    $employee = Employee::factory()->create([
        'employee_number' => 'EMP-PERM-01',
        'email' => 'perm@orbit.test',
    ]);
    app(TenantContext::class)->clear();

    // Soft delete the employee
    $deleteResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/employees/'.$employee->id);
    $deleteResp->assertStatus(200);

    $this->app['auth']->forgetGuards();

    // 1. Attempting to reuse employee_number in the same company fails validation with 422
    $recreateNumResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-PERM-01',
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'other-email@orbit.test',
        ]);
    $recreateNumResp->assertStatus(422)
        ->assertJsonValidationErrors(['employee_number']);

    $this->app['auth']->forgetGuards();

    // 2. Attempting to reuse email in the same company fails validation with 422
    $recreateEmailResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/employees', [
            'employee_number' => 'EMP-PERM-02',
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'perm@orbit.test',
        ]);
    $recreateEmailResp->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
