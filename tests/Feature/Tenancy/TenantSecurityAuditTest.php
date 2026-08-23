<?php

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register test routes with standard api, auth:sanctum, tenant middleware.
    // Thanks to middleware priority in bootstrap/app.php, EnsureTenantContext runs before SubstituteBindings,
    // ensuring Route Model Binding is strictly tenant-scoped.
    Route::get('/_test/users/{user}', function (User $user) {
        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'email' => $user->email,
            ],
        ]);
    })->middleware(['api', 'auth:sanctum', 'tenant']);

    Route::delete('/_test/users/{user}', function (User $user) {
        $user->delete();

        return response()->json(['message' => 'Deleted successfully.']);
    })->middleware(['api', 'auth:sanctum', 'tenant']);
});

test('company A user cannot read company B users via Eloquent queries', function () {
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);

    // Create User A in Company A
    app(TenantContext::class)->set($companyA);
    $userA = User::factory()->create(['email' => 'usera@companya.com']);
    app(TenantContext::class)->clear();

    // Create User B in Company B
    app(TenantContext::class)->set($companyB);
    $userB = User::factory()->create(['email' => 'userb@companyb.com']);
    app(TenantContext::class)->clear();

    // Authenticate as User A and establish Company A context
    app(TenantContext::class)->set($companyA);

    // 1. User::all() only returns User A
    $allUsers = User::all();
    expect($allUsers)->toHaveCount(1)
        ->and($allUsers->first()->id)->toBe($userA->id);

    // 2. User::find(User B) returns null
    expect(User::find($userB->id))->toBeNull();

    // 3. User::where('email', ...) for User B returns null
    expect(User::where('email', 'userb@companyb.com')->first())->toBeNull();

    // 4. User count is exactly 1 for Company A
    expect(User::count())->toBe(1);
});

test('company A user cannot update company B users via Eloquent or bulk query builder', function () {
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);

    app(TenantContext::class)->set($companyA);
    $userA = User::factory()->create(['name' => 'User A']);
    app(TenantContext::class)->clear();

    app(TenantContext::class)->set($companyB);
    $userB = User::factory()->create(['name' => 'User B']);
    app(TenantContext::class)->clear();

    // Act as Company A
    app(TenantContext::class)->set($companyA);

    // 1. Bulk update targeting User B by ID while in Company A scope updates 0 rows
    $affected = User::where('id', $userB->id)->update(['name' => 'Hacked Name']);
    expect($affected)->toBe(0);

    // Verify User B in database was NOT changed
    app(TenantContext::class)->clear();
    expect($userB->fresh()->name)->toBe('User B');

    // 2. Attempting to bulk update company_id itself throws InvalidArgumentException
    app(TenantContext::class)->set($companyA);
    expect(fn () => User::where('id', $userA->id)->update(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');
});

test('company A user cannot delete company B users via Eloquent or bulk query builder', function () {
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);

    app(TenantContext::class)->set($companyA);
    $userA = User::factory()->create();
    app(TenantContext::class)->clear();

    app(TenantContext::class)->set($companyB);
    $userB = User::factory()->create();
    app(TenantContext::class)->clear();

    // Act as Company A
    app(TenantContext::class)->set($companyA);

    // Bulk delete targeting User B by ID while in Company A scope deletes 0 rows
    $affected = User::where('id', $userB->id)->delete();
    expect($affected)->toBe(0);

    // Verify User B is still alive in database
    app(TenantContext::class)->clear();
    expect(User::withoutGlobalScopes()->find($userB->id)->deleted_at)->toBeNull();
});

test('route model binding returns 404 not found when attempting to access a user from another company', function () {
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);

    app(TenantContext::class)->set($companyA);
    $userA = User::factory()->create(['email' => 'usera@companya.com']);
    $tokenA = $userA->createToken('token-a')->plainTextToken;
    app(TenantContext::class)->clear();

    app(TenantContext::class)->set($companyB);
    $userB = User::factory()->create(['email' => 'userb@companyb.com']);
    app(TenantContext::class)->clear();

    // User A attempts to access their own user via route model binding -> 200 OK
    $responseOwn = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/_test/users/'.$userA->id);
    $responseOwn->assertStatus(200)
        ->assertJson([
            'user' => ['email' => 'usera@companya.com'],
        ]);

    $this->app['auth']->forgetGuards();

    // User A attempts to access User B via route model binding -> 404 NOT FOUND (anti-enumeration)
    $responseOther = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->getJson('/_test/users/'.$userB->id);
    $responseOther->assertStatus(404);

    $this->app['auth']->forgetGuards();

    // User A attempts to delete User B via route model binding -> 404 NOT FOUND
    $responseDeleteOther = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->deleteJson('/_test/users/'.$userB->id);
    $responseDeleteOther->assertStatus(404);

    // Verify User B is NOT deleted
    expect(User::withoutGlobalScopes()->find($userB->id)->deleted_at)->toBeNull();
});

test('registration transaction is atomic and cleans up tenant context in all failure conditions', function () {
    expect(app(TenantContext::class)->hasTenant())->toBeFalse();

    // Test with invalid email to trigger validation failure before transaction
    $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'Failed Co',
        'name' => 'Failed User',
        'email' => 'invalid-email-format',
        'password' => 'SecurePassword123!',
    ])->assertStatus(422);

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and(Company::where('name', 'Failed Co')->exists())->toBeFalse();

    // Test with valid payload: context is active during user creation, but cleared after registration
    $response = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'Atomic Success Co',
        'name' => 'Success Owner',
        'email' => 'owner@atomicsuccess.com',
        'password' => 'SecurePassword123!',
    ]);

    $response->assertStatus(201);

    // Verify TenantContext is cleared after response completes
    expect(app(TenantContext::class)->hasTenant())->toBeFalse();
});
