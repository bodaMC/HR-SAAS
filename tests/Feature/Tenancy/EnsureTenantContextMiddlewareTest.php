<?php

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register a temporary test route
    Route::get('/_test/tenant-route', function () {
        return response()->json([
            'success' => true,
            'tenant_id' => app(TenantContext::class)->id(),
        ]);
    })->middleware('tenant');
});

test('middleware returns 401 if request is unauthenticated', function () {
    $response = $this->getJson('/_test/tenant-route');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);
});

test('middleware returns 403 if user is inactive', function () {
    $company = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Active Company',
        'slug' => 'active-company',
        'is_active' => true,
    ]);

    // Establish TenantContext to create the user successfully
    app(TenantContext::class)->set($company);
    $user = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Inactive User',
        'email' => 'inactive@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => false, // Inactive user
    ]);
    app(TenantContext::class)->clear();

    $response = $this->actingAs($user)->getJson('/_test/tenant-route');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Your user account is inactive.',
            'code' => 'USER_INACTIVE',
        ]);
});

test('middleware returns 403 if company is inactive', function () {
    $company = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Inactive Company',
        'slug' => 'inactive-company',
        'is_active' => false, // Inactive company
    ]);

    app(TenantContext::class)->set($company);
    $user = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Active User',
        'email' => 'active@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);
    app(TenantContext::class)->clear();

    $response = $this->actingAs($user)->getJson('/_test/tenant-route');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Your company is inactive.',
            'code' => 'COMPANY_INACTIVE',
        ]);
});

test('middleware sets tenant context and proceeds for active user and active company', function () {
    $company = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Active Company',
        'slug' => 'active-company',
        'is_active' => true,
    ]);

    app(TenantContext::class)->set($company);
    $user = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Active User',
        'email' => 'active@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);
    app(TenantContext::class)->clear();

    $response = $this->actingAs($user)->getJson('/_test/tenant-route');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'tenant_id' => $company->id,
        ]);

    // Assert that the context has been safely cleared after request execution (terminable middleware lifecycle)
    expect(app(TenantContext::class)->hasTenant())->toBeFalse();
});
