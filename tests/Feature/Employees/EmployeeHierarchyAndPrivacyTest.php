<?php

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('manager hierarchy prevents self-manager and cycles', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $owner = User::factory()->owner()->hr()->create();
    $token = $owner->createToken('test-token')->plainTextToken;

    $manager = Employee::factory()->create(['first_name' => 'Boss']);
    $subordinate = Employee::factory()->create([
        'first_name' => 'Worker',
        'manager_id' => $manager->id,
    ]);
    app(TenantContext::class)->clear();

    // 1. Employee cannot be their own manager -> fails 422
    $selfManagerResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/employees/'.$manager->id, [
            'manager_id' => $manager->id,
        ]);
    $selfManagerResp->assertStatus(422)
        ->assertJsonValidationErrors(['manager_id']);

    $this->app['auth']->forgetGuards();

    // 2. Setting a subordinate as the manager creates a cycle -> fails 422
    $cycleResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/employees/'.$manager->id, [
            'manager_id' => $subordinate->id,
        ]);
    $cycleResp->assertStatus(422)
        ->assertJsonValidationErrors(['manager_id']);
});

test('personal data privacy: owner and admin can view personal data for all company employees', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $adminUser = User::factory()->hr()->create(['role' => $role]);
    $token = $adminUser->createToken('admin-token')->plainTextToken;

    $employee = Employee::factory()->create([
        'phone' => '+1999888777',
        'date_of_birth' => '1985-12-01',
        'gender' => Gender::Male,
        'national_id' => '29001011234567',
    ]);
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/employees/'.$employee->id);

    $response->assertStatus(200);

    // Personal data is present
    $data = $response->json('data');
    expect($data)->toHaveKey('phone', '+1999888777')
        ->and($data)->toHaveKey('date_of_birth', '1985-12-01')
        ->and($data)->toHaveKey('gender', 'male')
        ->and($data)->toHaveKey('national_id', '29001011234567');
})->with([
    'Owner' => UserRole::Owner,
    'Admin' => UserRole::Admin,
]);

test('personal data privacy: employee can view own personal data but not another employees personal data', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    // User & Employee A
    $userA = User::factory()->create(['role' => UserRole::Employee]);
    $tokenA = $userA->createToken('token-a')->plainTextToken;
    $employeeA = Employee::factory()->create([
        'user_id' => $userA->id,
        'phone' => '+111111111',
        'date_of_birth' => '1992-04-10',
        'gender' => Gender::Female,
        'national_id' => 'NAT-AAA-111',
    ]);

    // User & Employee B
    $userB = User::factory()->create(['role' => UserRole::Employee]);
    $employeeB = Employee::factory()->create([
        'user_id' => $userB->id,
        'phone' => '+222222222',
        'date_of_birth' => '1995-08-20',
        'gender' => Gender::Male,
        'national_id' => 'NAT-BBB-222',
    ]);
    app(TenantContext::class)->clear();

    // 1. Employee A views own record -> personal data IS present
    $ownResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/employees/'.$employeeA->id);

    $ownResponse->assertStatus(200);
    $ownData = $ownResponse->json('data');
    expect($ownData)->toHaveKey('phone', '+111111111')
        ->and($ownData)->toHaveKey('date_of_birth', '1992-04-10')
        ->and($ownData)->toHaveKey('gender', 'female')
        ->and($ownData)->toHaveKey('national_id', 'NAT-AAA-111');

    $this->app['auth']->forgetGuards();

    // 2. Employee A views Employee B -> basic data present, personal data IS MASKED / ABSENT
    $otherResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/api/v1/employees/'.$employeeB->id);

    // Phase 3.2: another employee's record needs employees.view (intentional change)
    $otherResponse->assertStatus(403);
});

test('personal data privacy: a manager has no hierarchy based directory access without an explicit permission', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    // Manager User & Employee Record
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    $managerToken = $managerUser->createToken('manager-token')->plainTextToken;
    $managerEmployee = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'first_name' => 'Manager',
    ]);

    // Subordinate 1 (Direct Report to Manager)
    $subordinate = Employee::factory()->create([
        'first_name' => 'Direct',
        'manager_id' => $managerEmployee->id,
        'phone' => '+133333333',
        'national_id' => 'NAT-SUB-1',
    ]);

    // Subordinate 2 (Indirect Report: Reports to Subordinate 1)
    $nestedSubordinate = Employee::factory()->create([
        'first_name' => 'Nested',
        'manager_id' => $subordinate->id,
        'phone' => '+144444444',
        'national_id' => 'NAT-SUB-2',
    ]);

    // Unrelated Employee (Reports to another manager)
    $unrelatedEmployee = Employee::factory()->create([
        'first_name' => 'Unrelated',
        'manager_id' => null,
        'phone' => '+155555555',
        'national_id' => 'NAT-OTHER',
    ]);
    app(TenantContext::class)->clear();

    // Phase 3.2: a Manager role or reporting line grants no directory access (intentional change).
    foreach ([$subordinate, $nestedSubordinate, $unrelatedEmployee] as $target) {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/employees/'.$target->id)
            ->assertStatus(403);
    }

    // Own record stays available (self-service).
    $this->app['auth']->forgetGuards();
    $own = $this->withHeader('Authorization', 'Bearer '.$managerToken)->getJson('/api/v1/employees/'.$managerEmployee->id);
    $own->assertStatus(200);
});

test('employee list endpoint is denied for users without employees.view', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $regularUser = User::factory()->create(['role' => UserRole::Employee]);
    $token = $regularUser->createToken('token')->plainTextToken;
    $ownEmployee = Employee::factory()->create([
        'user_id' => $regularUser->id,
        'phone' => '+100000000',
    ]);

    $otherEmployee = Employee::factory()->create([
        'phone' => '+200000000',
    ]);
    app(TenantContext::class)->clear();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/employees');

    // Phase 3.2: the directory listing requires employees.view (intentional change).
    $response->assertStatus(403);
});
