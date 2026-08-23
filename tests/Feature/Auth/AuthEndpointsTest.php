<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────
// Registration Tests
// ─────────────────────────────────────────────

test('can register a company and owner user atomically', function () {
    $payload = [
        'company_name' => 'Acme Corporation',
        'company_slug' => 'acme-corp',
        'name' => 'John Doe',
        'email' => 'john@acme.com',
        'password' => 'SecurePass123!',
        'phone' => '+1234567890',
    ];

    $response = $this->postJson('/api/v1/auth/register-company', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => [
                'id',
                'uuid',
                'name',
                'email',
                'phone',
                'role',
                'is_active',
                'created_at',
            ],
            'company' => [
                'id',
                'uuid',
                'name',
                'slug',
                'is_active',
                'created_at',
            ],
        ])
        ->assertJson([
            'message' => 'Company and owner registered successfully.',
            'token_type' => 'Bearer',
            'user' => [
                'name' => 'John Doe',
                'email' => 'john@acme.com',
                'phone' => '+1234567890',
                'role' => 'owner',
                'is_active' => true,
            ],
            'company' => [
                'name' => 'Acme Corporation',
                'slug' => 'acme-corp',
                'is_active' => true,
            ],
        ]);

    // Verify in database
    $company = Company::where('slug', 'acme-corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->name)->toBe('Acme Corporation')
        ->and($company->uuid)->not->toBeNull();

    // Verify user in database with correct company_id and Owner role
    $user = User::withoutGlobalScopes()->where('email', 'john@acme.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->company_id)->toBe($company->id)
        ->and($user->role)->toBe(UserRole::Owner)
        ->and($user->uuid)->not->toBeNull();

    // Verify password is encrypted
    expect(Hash::check('SecurePass123!', $user->password))->toBeTrue();
});

test('registration auto-generates slug if not provided', function () {
    $payload = [
        'company_name' => 'Beta Global Solutions',
        'name' => 'Alice Smith',
        'email' => 'alice@betaglobal.com',
        'password' => 'SecurePass123!',
    ];

    $response = $this->postJson('/api/v1/auth/register-company', $payload);

    $response->assertStatus(201);

    $company = Company::where('name', 'Beta Global Solutions')->first();
    expect($company)->not->toBeNull()
        ->and($company->slug)->toContain('beta-global-solutions');
});

test('registration forces server-side role, uuid, and company_id, ignoring client overrides', function () {
    $fakeCompany = Company::factory()->create();

    $payload = [
        'company_name' => 'Cyberdyne Systems',
        'name' => 'Miles Dyson',
        'email' => 'miles@cyberdyne.com',
        'password' => 'SecurePass123!',
        // Malicious client attempt to spoof role, company_id, and uuid
        'role' => 'employee',
        'company_id' => $fakeCompany->id,
        'uuid' => '00000000-0000-0000-0000-000000000000',
    ];

    $response = $this->postJson('/api/v1/auth/register-company', $payload);

    $response->assertStatus(201);

    $user = User::withoutGlobalScopes()->where('email', 'miles@cyberdyne.com')->first();
    $newCompany = Company::where('name', 'Cyberdyne Systems')->first();

    // Must be assigned Owner role and new company_id, NOT the spoofed values
    expect($user->role)->toBe(UserRole::Owner)
        ->and($user->company_id)->toBe($newCompany->id)
        ->and($user->company_id)->not->toBe($fakeCompany->id)
        ->and($user->uuid)->not->toBe('00000000-0000-0000-0000-000000000000');
});

test('registration fails validation with duplicate email or duplicate slug', function () {
    // Create an existing user and company
    $existingCompany = Company::factory()->create(['slug' => 'taken-slug']);
    app(TenantContext::class)->set($existingCompany);
    User::factory()->create(['email' => 'taken@example.com']);
    app(TenantContext::class)->clear();

    // Duplicate email
    $responseEmail = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'New Co',
        'name' => 'User',
        'email' => 'taken@example.com',
        'password' => 'SecurePass123!',
    ]);
    $responseEmail->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Duplicate company_slug
    $responseSlug = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'New Co',
        'company_slug' => 'taken-slug',
        'name' => 'User',
        'email' => 'new@example.com',
        'password' => 'SecurePass123!',
    ]);
    $responseSlug->assertStatus(422)
        ->assertJsonValidationErrors(['company_slug']);
});

test('registration rolls back database changes if an error occurs', function () {
    // Attempt registration with invalid password to cause validation failure
    $response = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'Doomed Company',
        'name' => 'Doomed User',
        'email' => 'doomed@example.com',
        'password' => 'short', // Too short for default password rule
    ]);

    $response->assertStatus(422);

    // Assert that the company was not created in the database
    expect(Company::where('name', 'Doomed Company')->exists())->toBeFalse();
    expect(User::withoutGlobalScopes()->where('email', 'doomed@example.com')->exists())->toBeFalse();
});

// ─────────────────────────────────────────────
// Login Tests
// ─────────────────────────────────────────────

test('user can authenticate with valid credentials', function () {
    $company = Company::factory()->create(['name' => 'Stark Industries']);
    app(TenantContext::class)->set($company);

    $user = User::factory()->create([
        'email' => 'tony@stark.com',
        'password' => 'Jarvis123!',
        'role' => UserRole::Owner,
    ]);
    app(TenantContext::class)->clear();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'tony@stark.com',
        'password' => 'Jarvis123!',
        'device_name' => 'test-device',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => ['id', 'uuid', 'name', 'email', 'role', 'is_active'],
            'company' => ['id', 'uuid', 'name', 'slug', 'is_active'],
        ])
        ->assertJson([
            'message' => 'Authenticated successfully.',
            'token_type' => 'Bearer',
            'user' => [
                'email' => 'tony@stark.com',
                'role' => 'owner',
            ],
            'company' => [
                'name' => 'Stark Industries',
            ],
        ]);

    // Token should be valid and authenticated
    $token = $response->json('token');
    $meResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me');

    $meResponse->assertStatus(200)
        ->assertJson([
            'user' => ['email' => 'tony@stark.com'],
        ]);
});

test('login fails with invalid password or non-existent email', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    User::factory()->create([
        'email' => 'valid@example.com',
        'password' => 'CorrectPassword123!',
    ]);
    app(TenantContext::class)->clear();

    // Wrong password
    $responseWrongPass = $this->postJson('/api/v1/auth/login', [
        'email' => 'valid@example.com',
        'password' => 'WrongPassword!',
    ]);
    $responseWrongPass->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // Non-existent email
    $responseNonExistent = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'CorrectPassword123!',
    ]);
    $responseNonExistent->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('login fails if user is inactive', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    User::factory()->inactive()->create([
        'email' => 'inactive@example.com',
        'password' => 'ValidPass123!',
    ]);
    app(TenantContext::class)->clear();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'ValidPass123!',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Your user account is inactive.',
            'code' => 'USER_INACTIVE',
        ]);
});

test('login fails if company is inactive', function () {
    $company = Company::factory()->inactive()->create();
    app(TenantContext::class)->set($company);

    User::factory()->create([
        'email' => 'user_inactive_company@example.com',
        'password' => 'ValidPass123!',
    ]);
    app(TenantContext::class)->clear();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user_inactive_company@example.com',
        'password' => 'ValidPass123!',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Your company is inactive.',
            'code' => 'COMPANY_INACTIVE',
        ]);
});

// ─────────────────────────────────────────────
// Logout Tests
// ─────────────────────────────────────────────

test('authenticated user can logout and revoke current token only', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();
    app(TenantContext::class)->clear();

    // Create two separate tokens for the user
    $token1 = $user->createToken('device-1')->plainTextToken;
    $token2 = $user->createToken('device-2')->plainTextToken;

    expect($user->tokens()->count())->toBe(2);

    // Logout using token1
    $response = $this->withHeader('Authorization', 'Bearer '.$token1)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Logged out successfully.']);

    // token1 is now revoked
    expect($user->fresh()->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();

    $testToken1 = $this->withHeader('Authorization', 'Bearer '.$token1)
        ->getJson('/api/v1/auth/me');
    $testToken1->assertStatus(401);

    $this->app['auth']->forgetGuards();

    // token2 is still valid
    $testToken2 = $this->withHeader('Authorization', 'Bearer '.$token2)
        ->getJson('/api/v1/auth/me');
    $testToken2->assertStatus(200);
});

test('unauthenticated request to logout returns 401', function () {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

// ─────────────────────────────────────────────
// Me Endpoint Tests
// ─────────────────────────────────────────────

test('me endpoint returns authenticated user profile and company info', function () {
    $company = Company::factory()->create(['name' => 'Wayne Enterprises', 'slug' => 'wayne-ent']);
    app(TenantContext::class)->set($company);

    $user = User::factory()->create([
        'name' => 'Bruce Wayne',
        'email' => 'bruce@wayne.com',
        'role' => UserRole::Owner,
    ]);
    app(TenantContext::class)->clear();

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJson([
            'user' => [
                'name' => 'Bruce Wayne',
                'email' => 'bruce@wayne.com',
                'role' => 'owner',
            ],
            'company' => [
                'name' => 'Wayne Enterprises',
                'slug' => 'wayne-ent',
            ],
        ]);

    // Ensure password and remember_token are NOT exposed in JSON response
    $json = $response->json();
    expect($json['user'])->not->toHaveKey('password')
        ->and($json['user'])->not->toHaveKey('remember_token');
});

test('unauthenticated request to me returns 401', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('me endpoint strictly returns only the authenticated users company and profile across tenants', function () {
    // Tenant A
    $companyA = Company::factory()->create(['name' => 'Company Alpha', 'slug' => 'company-alpha']);
    app(TenantContext::class)->set($companyA);
    $userA = User::factory()->create(['name' => 'Alpha User', 'email' => 'alpha@example.com']);
    $tokenA = $userA->createToken('token-a')->plainTextToken;
    app(TenantContext::class)->clear();

    // Tenant B
    $companyB = Company::factory()->create(['name' => 'Company Beta', 'slug' => 'company-beta']);
    app(TenantContext::class)->set($companyB);
    $userB = User::factory()->create(['name' => 'Beta User', 'email' => 'beta@example.com']);
    $tokenB = $userB->createToken('token-b')->plainTextToken;
    app(TenantContext::class)->clear();

    // Request from Tenant A
    $responseA = $this->withHeader('Authorization', 'Bearer '.$tokenA)->getJson('/api/v1/auth/me');
    $responseA->assertStatus(200)
        ->assertJson([
            'user' => ['name' => 'Alpha User', 'email' => 'alpha@example.com'],
            'company' => ['name' => 'Company Alpha', 'slug' => 'company-alpha'],
        ]);

    $this->app['auth']->forgetGuards();

    // Request from Tenant B
    $responseB = $this->withHeader('Authorization', 'Bearer '.$tokenB)->getJson('/api/v1/auth/me');
    $responseB->assertStatus(200)
        ->assertJson([
            'user' => ['name' => 'Beta User', 'email' => 'beta@example.com'],
            'company' => ['name' => 'Company Beta', 'slug' => 'company-beta'],
        ]);
});

test('me endpoint returns 403 when user account or company is deactivated after login', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->create(['is_active' => true]);
    $token = $user->createToken('token')->plainTextToken;
    app(TenantContext::class)->clear();

    // Verify initially active
    $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me');
    $response->assertStatus(200);

    // Deactivate user in database
    $user->update(['is_active' => false]);
    $this->app['auth']->forgetGuards();

    $responseInactiveUser = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me');
    $responseInactiveUser->assertStatus(403)
        ->assertJson(['code' => 'USER_INACTIVE']);

    // Reactivate user, but deactivate company
    $user->update(['is_active' => true]);
    $company->update(['is_active' => false]);
    $this->app['auth']->forgetGuards();

    $responseInactiveCompany = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me');
    $responseInactiveCompany->assertStatus(403)
        ->assertJson(['code' => 'COMPANY_INACTIVE']);
});

// ─────────────────────────────────────────────
// Token Expiration & Contract Security Tests
// ─────────────────────────────────────────────

test('user resource never exposes internal company_id in public responses', function () {
    $payload = [
        'company_name' => 'Zero Leakage Inc',
        'name' => 'Alice Secret',
        'email' => 'alice@zeroleak.com',
        'password' => 'SecurePass123!',
    ];

    $response = $this->postJson('/api/v1/auth/register-company', $payload);
    $response->assertStatus(201);

    $userData = $response->json('user');
    expect($userData)->not->toHaveKey('company_id')
        ->and($userData['uuid'])->toBeString()
        ->and($userData['company']['uuid'])->toBeString();
});

test('registration issues tokens with explicit expiration set from config', function () {
    config(['sanctum.expiration' => 120]); // 2 hours

    $response = $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'Expiring Co',
        'name' => 'Exp User',
        'email' => 'exp@example.com',
        'password' => 'SecurePass123!',
    ]);

    $response->assertStatus(201);

    $user = User::withoutGlobalScopes()->where('email', 'exp@example.com')->first();
    $tokenRecord = $user->tokens()->first();

    expect($tokenRecord->expires_at)->not->toBeNull()
        ->and($tokenRecord->expires_at->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($tokenRecord->expires_at))->toBeGreaterThanOrEqual(119);
});

test('login issues tokens with explicit expiration set from config', function () {
    config(['sanctum.expiration' => 60]); // 1 hour

    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    User::factory()->create([
        'email' => 'login_exp@example.com',
        'password' => 'ValidPass123!',
    ]);
    app(TenantContext::class)->clear();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login_exp@example.com',
        'password' => 'ValidPass123!',
    ]);

    $response->assertStatus(200);

    $user = User::withoutGlobalScopes()->where('email', 'login_exp@example.com')->first();
    $tokenRecord = $user->tokens()->first();

    expect($tokenRecord->expires_at)->not->toBeNull()
        ->and($tokenRecord->expires_at->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($tokenRecord->expires_at))->toBeGreaterThanOrEqual(59);
});

test('expired sanctum tokens are rejected with 401 unauthorized', function () {
    config(['sanctum.expiration' => 60]); // 60 minutes

    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->create();
    app(TenantContext::class)->clear();

    // Create an expired token by setting created_at and expires_at in the past
    $token = $user->createToken('expired-device', ['*'], now()->subMinutes(10))->plainTextToken;
    $tokenRecord = $user->tokens()->latest()->first();
    $tokenRecord->forceFill([
        'created_at' => now()->subMinutes(120),
        'expires_at' => now()->subMinutes(60),
    ])->save();

    $this->app['auth']->forgetGuards();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});
