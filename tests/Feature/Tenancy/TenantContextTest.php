<?php

use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('tenant context can be set, retrieved, and cleared', function () {
    $tenantContext = app(TenantContext::class);

    expect($tenantContext->hasTenant())->toBeFalse()
        ->and($tenantContext->id())->toBeNull()
        ->and($tenantContext->get())->toBeNull();

    $company = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company A',
        'slug' => 'company-a',
        'is_active' => true,
    ]);

    $tenantContext->set($company);

    expect($tenantContext->hasTenant())->toBeTrue()
        ->and($tenantContext->id())->toBe($company->id)
        ->and($tenantContext->get()->id)->toBe($company->id);

    $tenantContext->clear();

    expect($tenantContext->hasTenant())->toBeFalse()
        ->and($tenantContext->id())->toBeNull()
        ->and($tenantContext->get())->toBeNull();
});

test('belongstocompany trait automatically assigns company_id and scopes queries', function () {
    $companyA = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company A',
        'slug' => 'company-a',
        'is_active' => true,
    ]);

    $companyB = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company B',
        'slug' => 'company-b',
        'is_active' => true,
    ]);

    $tenantContext = app(TenantContext::class);

    // 1. Create User A under Company A
    $tenantContext->set($companyA);
    $userA = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'User A',
        'email' => 'usera@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);

    expect($userA->company_id)->toBe($companyA->id);

    // 2. Create User B under Company B
    $tenantContext->set($companyB);
    $userB = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'User B',
        'email' => 'userb@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);

    expect($userB->company_id)->toBe($companyB->id);

    // 3. Test Scoping: Set tenant to Company A
    $tenantContext->set($companyA);

    $users = User::all();
    expect($users->count())->toBe(1)
        ->and($users->first()->id)->toBe($userA->id);

    // Verify we cannot find User B while scoped to Company A
    $foundUserB = User::find($userB->id);
    expect($foundUserB)->toBeNull();

    // 4. Test Scoping: Set tenant to Company B
    $tenantContext->set($companyB);

    $users = User::all();
    expect($users->count())->toBe(1)
        ->and($users->first()->id)->toBe($userB->id);

    // Verify we cannot find User A while scoped to Company B
    $foundUserA = User::find($userA->id);
    expect($foundUserA)->toBeNull();

    // 5. Test bypass when no tenant is set
    $tenantContext->clear();

    $users = User::all();
    expect($users->count())->toBe(2);
});

test('belongstocompany trait prevents model company_id mutations by throwing exception', function () {
    $companyA = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company A',
        'slug' => 'company-a',
        'is_active' => true,
    ]);

    $companyB = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company B',
        'slug' => 'company-b',
        'is_active' => true,
    ]);

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($companyA);

    $user = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);

    expect($user->company_id)->toBe($companyA->id);

    // Attempting to manually update company_id to Company B via model save/update must throw InvalidArgumentException
    expect(fn () => $user->update(['company_id' => $companyB->id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $user->setAttribute('company_id', $companyB->id))->toThrow(InvalidArgumentException::class);

    // Verify it remained Company A in database
    expect($user->fresh()->company_id)->toBe($companyA->id);
});

test('belongstocompany trait prevents query builder and bulk updates to company_id by throwing exception', function () {
    $companyA = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company A',
        'slug' => 'company-a',
        'is_active' => true,
    ]);

    $companyB = Company::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Company B',
        'slug' => 'company-b',
        'is_active' => true,
    ]);

    $tenantContext = app(TenantContext::class);
    $tenantContext->set($companyA);

    $user = User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'User',
        'email' => 'user@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]);

    // Attempting to bulk update company_id via Query Builder must throw InvalidArgumentException
    expect(fn () => User::where('id', $user->id)->update(['company_id' => $companyB->id]))->toThrow(InvalidArgumentException::class);

    // Verify it remained Company A in database
    expect($user->fresh()->company_id)->toBe($companyA->id);
});

test('belongstocompany trait throws exception if creating record without tenant context', function () {
    $tenantContext = app(TenantContext::class);
    $tenantContext->clear();

    // Attempting to create a user when no TenantContext is set must throw RuntimeException
    expect(fn () => User::create([
        'uuid' => Str::uuid()->toString(),
        'name' => 'Orphaned User',
        'email' => 'orphan@example.com',
        'password' => bcrypt('password'),
        'role' => 'employee',
        'is_active' => true,
    ]))->toThrow(RuntimeException::class, 'Tenant context is missing. Cannot create tenant-scoped model.');
});
