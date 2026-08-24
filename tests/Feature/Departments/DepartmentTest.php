<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner and admin can list and create departments', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test-token')->plainTextToken;
    app(TenantContext::class)->clear();

    // 1. Create Department
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/departments', [
            'name' => 'Human Resources',
            'description' => 'Handles personnel and HR operations',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'name' => 'Human Resources',
                'description' => 'Handles personnel and HR operations',
                'is_active' => true,
            ],
        ]);

    $this->app['auth']->forgetGuards();

    // 2. List Departments
    $listResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/departments');

    $listResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Human Resources');
})->with([
    'Owner' => UserRole::Owner,
    'Admin' => UserRole::Admin,
]);

test('manager and employee can view but cannot create, update, or delete departments', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test-token')->plainTextToken;

    $department = Department::factory()->create(['name' => 'Engineering']);
    app(TenantContext::class)->clear();

    // 1. View is allowed
    $viewResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/departments/'.$department->id);
    $viewResponse->assertStatus(200)
        ->assertJsonPath('data.name', 'Engineering');

    $this->app['auth']->forgetGuards();

    // 2. Create is forbidden (403)
    $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/departments', ['name' => 'Finance']);
    $createResponse->assertStatus(403);

    $this->app['auth']->forgetGuards();

    // 3. Update is forbidden (403)
    $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/departments/'.$department->id, ['name' => 'Software Eng']);
    $updateResponse->assertStatus(403);

    $this->app['auth']->forgetGuards();

    // 4. Delete is forbidden (403)
    $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/departments/'.$department->id);
    $deleteResponse->assertStatus(403);
})->with([
    'Manager' => UserRole::Manager,
    'Employee' => UserRole::Employee,
]);

test('department name must be unique within the same company only', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Create "Marketing" in Company A
    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    Department::factory()->create(['name' => 'Marketing']);
    app(TenantContext::class)->clear();

    // Attempting duplicate "Marketing" in Company A fails with 422
    $duplicateResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/departments', ['name' => 'Marketing']);
    $duplicateResponse->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->app['auth']->forgetGuards();

    // Company B can create "Marketing" with no conflict
    app(TenantContext::class)->set($companyB);
    $ownerB = User::factory()->owner()->create();
    $tokenB = $ownerB->createToken('token-b')->plainTextToken;
    app(TenantContext::class)->clear();

    $responseB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
        ->postJson('/api/v1/departments', ['name' => 'Marketing']);
    $responseB->assertStatus(201)
        ->assertJsonPath('data.name', 'Marketing');
});

test('department soft delete works and maintains tenant isolation', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    $deptA = Department::factory()->create(['name' => 'Dept A']);
    app(TenantContext::class)->clear();

    app(TenantContext::class)->set($companyB);
    $ownerB = User::factory()->owner()->create();
    $tokenB = $ownerB->createToken('token-b')->plainTextToken;
    $deptB = Department::factory()->create(['name' => 'Dept B']);
    app(TenantContext::class)->clear();

    // Owner A deletes Dept A
    $deleteResp = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->deleteJson('/api/v1/departments/'.$deptA->id);
    $deleteResp->assertStatus(200);

    // Dept A is soft deleted
    expect(Department::withoutGlobalScopes()->find($deptA->id)->deleted_at)->not->toBeNull();

    $this->app['auth']->forgetGuards();

    // Owner A cannot access Dept B (404 Not Found anti-enumeration)
    $crossAccessResp = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/departments/'.$deptB->id);
    $crossAccessResp->assertStatus(404);
});

test('department name remains permanently reserved after soft deletion', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $owner = User::factory()->owner()->create();
    $token = $owner->createToken('token')->plainTextToken;
    $dept = Department::factory()->create(['name' => 'Accounting']);
    app(TenantContext::class)->clear();

    // Soft delete the department
    $deleteResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/departments/'.$dept->id);
    $deleteResp->assertStatus(200);

    $this->app['auth']->forgetGuards();

    // Attempting to recreate "Accounting" in the same company fails validation with 422
    $recreateResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/departments', ['name' => 'Accounting']);
    $recreateResp->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});
