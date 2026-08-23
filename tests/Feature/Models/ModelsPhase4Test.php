<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────
// UserRole Enum
// ─────────────────────────────────────────────

test('userrole enum has the correct backed string values', function () {
    expect(UserRole::Owner->value)->toBe('owner')
        ->and(UserRole::Admin->value)->toBe('admin')
        ->and(UserRole::Manager->value)->toBe('manager')
        ->and(UserRole::Employee->value)->toBe('employee');
});

test('userrole enum can be created from a string value', function () {
    expect(UserRole::from('owner'))->toBe(UserRole::Owner)
        ->and(UserRole::from('admin'))->toBe(UserRole::Admin)
        ->and(UserRole::from('manager'))->toBe(UserRole::Manager)
        ->and(UserRole::from('employee'))->toBe(UserRole::Employee);
});

test('userrole enum returns correct labels', function () {
    expect(UserRole::Owner->label())->toBe('Owner')
        ->and(UserRole::Admin->label())->toBe('Admin')
        ->and(UserRole::Manager->label())->toBe('Manager')
        ->and(UserRole::Employee->label())->toBe('Employee');
});

test('userrole enum has exactly four cases', function () {
    expect(UserRole::cases())->toHaveCount(4);
});

// ─────────────────────────────────────────────
// Company UUID Generation
// ─────────────────────────────────────────────

test('company uuid is automatically generated on creation', function () {
    $company = Company::factory()->create();

    expect($company->uuid)->not->toBeNull()
        ->and($company->uuid)->toBeString()
        ->and(strlen($company->uuid))->toBe(36) // Standard UUID v4 length
        ->and($company->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

test('each company receives a unique uuid', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    expect($companyA->uuid)->not->toBe($companyB->uuid);
});

test('company uuid is persisted to the database', function () {
    $company = Company::factory()->create();

    $fromDb = Company::withoutGlobalScopes()->find($company->id);

    expect($fromDb->uuid)->toBe($company->uuid);
});

// ─────────────────────────────────────────────
// User UUID Generation
// ─────────────────────────────────────────────

test('user uuid is automatically generated on creation', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();

    expect($user->uuid)->not->toBeNull()
        ->and($user->uuid)->toBeString()
        ->and(strlen($user->uuid))->toBe(36)
        ->and($user->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

test('each user receives a unique uuid', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    expect($userA->uuid)->not->toBe($userB->uuid);
});

test('user uuid is persisted to the database', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();

    // Retrieve fresh from database bypassing scopes to confirm persistence
    $fromDb = User::withoutGlobalScopes()->find($user->id);

    expect($fromDb->uuid)->toBe($user->uuid);
});

// ─────────────────────────────────────────────
// UserRole Casting
// ─────────────────────────────────────────────

test('user role is cast to userrole enum', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => UserRole::Admin]);

    expect($user->role)->toBeInstanceOf(UserRole::class)
        ->and($user->role)->toBe(UserRole::Admin);
});

test('user role cast survives database round-trip', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => UserRole::Manager]);

    $fromDb = User::withoutGlobalScopes()->find($user->id);

    expect($fromDb->role)->toBeInstanceOf(UserRole::class)
        ->and($fromDb->role)->toBe(UserRole::Manager)
        ->and($fromDb->role->value)->toBe('manager');
});

test('default user role from factory is employee', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::Employee);
});

test('userfactory role states produce correct userrole enums', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->create();

    expect($owner->role)->toBe(UserRole::Owner)
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($manager->role)->toBe(UserRole::Manager);
});

// ─────────────────────────────────────────────
// Company / User Relationship
// ─────────────────────────────────────────────

test('company has many users relationship works', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    User::factory()->count(3)->create();

    expect($company->users)->toHaveCount(3)
        ->and($company->users->first())->toBeInstanceOf(User::class);
});

test('user belongs to company relationship works', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();

    expect($user->company)->toBeInstanceOf(Company::class)
        ->and($user->company->id)->toBe($company->id);
});

test('user company_id matches the parent company id', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();

    expect($user->company_id)->toBe($company->id);
});

// ─────────────────────────────────────────────
// Soft Deletes
// ─────────────────────────────────────────────

test('company soft delete sets deleted_at and excludes from queries', function () {
    $company = Company::factory()->create();

    $company->delete();

    // withTrashed() confirms deleted_at is set
    expect(Company::withTrashed()->find($company->id)->deleted_at)->not->toBeNull();

    // Standard find() (applies SoftDeletingScope) returns null for soft-deleted records
    expect(Company::find($company->id))->toBeNull();
});

test('company can be restored after soft delete', function () {
    $company = Company::factory()->create();
    $company->delete();

    $company->restore();

    expect(Company::withoutGlobalScopes()->find($company->id))->not->toBeNull()
        ->and(Company::withoutGlobalScopes()->find($company->id)->deleted_at)->toBeNull();
});

test('user soft delete sets deleted_at and excludes from queries', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();
    $userId = $user->id;

    $user->delete();

    // User is gone from scoped queries
    expect(User::find($userId))->toBeNull();

    // But accessible via withTrashed
    $trashed = User::withTrashed()->find($userId);
    expect($trashed)->not->toBeNull()
        ->and($trashed->deleted_at)->not->toBeNull();
});

test('user can be restored after soft delete', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create();
    $user->delete();

    $user->restore();

    expect(User::find($user->id))->not->toBeNull()
        ->and(User::find($user->id)->deleted_at)->toBeNull();
});

// ─────────────────────────────────────────────
// company_id Remains Protected (Phase 4 additions)
// ─────────────────────────────────────────────

test('company_id is not included in user fillable and cannot be set via update payload', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    app(TenantContext::class)->set($companyA);

    $user = User::factory()->create();
    expect($user->company_id)->toBe($companyA->id);

    // Attempt to change company_id via update() — should throw
    expect(fn () => $user->update(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');

    // Confirm database value unchanged
    expect($user->fresh()->company_id)->toBe($companyA->id);
});

test('company_id cannot be mutated via fill() on existing user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    app(TenantContext::class)->set($companyA);

    $user = User::factory()->create();

    expect(fn () => $user->fill(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');
});

test('company_id cannot be mutated via query builder bulk update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    app(TenantContext::class)->set($companyA);

    $user = User::factory()->create();

    expect(fn () => User::where('id', $user->id)->update(['company_id' => $companyB->id]))
        ->toThrow(InvalidArgumentException::class, 'Tenant company_id cannot be mutated.');

    expect($user->fresh()->company_id)->toBe($companyA->id);
});

// ─────────────────────────────────────────────
// Company is_active Casting
// ─────────────────────────────────────────────

test('company is_active is cast to boolean', function () {
    $active = Company::factory()->create(['is_active' => true]);
    $inactive = Company::factory()->inactive()->create();

    expect($active->is_active)->toBeTrue()
        ->and($inactive->is_active)->toBeFalse();
});
