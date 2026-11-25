<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public registration with the former privileged email cannot grant system privileges', function () {
    $response = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'Regression tenant',
        'name' => 'Untrusted registrant',
        'email' => 'superadmin@orbit.test',
        'password' => bin2hex(random_bytes(16)),
        'is_super_admin' => true,
        'role' => 'admin',
    ])->assertCreated();

    $user = User::withoutGlobalScopes()->where('email', 'superadmin@orbit.test')->firstOrFail();
    expect($user->is_super_admin)->toBeFalse()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->role)->toBe(UserRole::Owner);

    $this->withToken($response->json('token'))->getJson('/api/v1/super-admin/tenants')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('token'))->postJson('/api/v1/super-admin/override-workflow', [
        'workflow_id' => 1, 'decision' => 'approve',
    ])->assertForbidden();
});

test('configured email never grants privilege without the explicit flag', function () {
    config(['app.super_admin_email' => 'configured@example.test']);
    $user = new User;
    $user->email = 'configured@example.test';
    $user->is_super_admin = false;
    expect($user->isSuperAdmin())->toBeFalse();
});

test('tenant roles cannot mass assign system privilege or call system endpoints', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->create(['role' => $role]);
    // Factory construction is server-controlled; ordinary fill/update must stay guarded.
    expect($user->isFillable('is_super_admin'))->toBeFalse();
    $wasStrict = Model::preventsSilentlyDiscardingAttributes();
    Model::preventSilentlyDiscardingAttributes(false);
    try {
        $user->fill(['is_super_admin' => true])->save();
    } finally {
        Model::preventSilentlyDiscardingAttributes($wasStrict);
    }
    expect($user->fresh()->is_super_admin)->toBeFalse()
        ->and($user->fresh()->isSuperAdmin())->toBeFalse();
    $token = $user->createToken('regression')->plainTextToken;
    app(TenantContext::class)->clear();
    $this->withToken($token)->getJson('/api/v1/super-admin/tenants')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/super-admin/override-workflow', [
        'workflow_id' => 1, 'decision' => 'approve', 'is_super_admin' => true,
    ])->assertForbidden();
})->with([UserRole::Employee, UserRole::Manager, UserRole::Admin, UserRole::Owner]);

test('explicitly provisioned system administrator retains login and system access independent of email', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $password = bin2hex(random_bytes(16));
    $user = User::factory()->create(['password' => $password]);
    // Trusted test fixture provisioning, never public input or a real account mutation.
    $user->is_super_admin = true;
    $user->save();
    app(TenantContext::class)->clear();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();
    expect($user->fresh()->isSuperAdmin())->toBeTrue();
    $this->withToken($response->json('token'))->getJson('/api/v1/super-admin/tenants')
        ->assertOk()->assertJsonPath('super_admin', true)
        ->assertJsonFragment(['uuid' => $otherCompany->uuid]);
    $this->app['auth']->forgetGuards();
    // Validation is reached only after authorization, unlike an unprivileged caller's 403.
    $this->withToken($response->json('token'))->postJson('/api/v1/super-admin/override-workflow', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['workflow_id', 'decision']);
});

test('unflagged tenant administrator cannot access another tenants data', function () {
    $ownCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->set($ownCompany);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('regression')->plainTextToken;
    app(TenantContext::class)->set($otherCompany);
    $department = Department::factory()->create();
    app(TenantContext::class)->clear();
    $this->withToken($token)->getJson('/api/v1/departments/'.$department->id)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/super-admin/tenants')->assertForbidden();
});

test('tenant users cannot promote themselves or others through employee updates', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $actor = $role === UserRole::Employee ? User::factory()->create(['role' => $role]) : User::factory()->hr()->create(['role' => $role]);
    $other = User::factory()->create();
    $ownEmployee = Employee::factory()->create(['user_id' => $actor->id]);
    $otherEmployee = Employee::factory()->create(['user_id' => $other->id]);
    $token = $actor->createToken('regression')->plainTextToken;
    app(TenantContext::class)->clear();

    foreach ([$ownEmployee, $otherEmployee] as $employee) {
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token)->putJson('/api/v1/employees/'.$employee->id, [
            'is_super_admin' => true,
            'user' => ['is_super_admin' => true],
            'role' => 'owner',
        ]);
        if ($role === UserRole::Employee) {
            $response->assertForbidden();
        } else {
            $response->assertOk();
        }
        expect($actor->fresh()->is_super_admin)->toBeFalse()
            ->and($other->fresh()->is_super_admin)->toBeFalse();
    }
})->with([UserRole::Employee, UserRole::Owner, UserRole::Admin]);

test('former privileged email never bypasses audit logging and platform actions are audited', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->create(['email' => 'superadmin@orbit.test']);
    $entry = app(AuditLogger::class)->log('regression-check', user: $user);
    expect($entry)->not->toBeNull()->and($entry->user_id)->toBe($user->id);
    $user->is_super_admin = true;
    $user->save();
    // Phase 3.2: Super Admin operations are audited too (intentional change from the old exemption).
    $audited = app(AuditLogger::class)->log('trusted-regression-check', user: $user);
    expect($audited)->not->toBeNull()->and($audited->user_id)->toBe($user->id);
    app(TenantContext::class)->clear();
});
