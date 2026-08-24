<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\JobTitle;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner and admin can list, create, update, and delete job titles', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test-token')->plainTextToken;
    app(TenantContext::class)->clear();

    // 1. Create Job Title
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/job-titles', [
            'name' => 'Senior Backend Engineer',
            'description' => 'Builds scalable APIs',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Senior Backend Engineer');

    $jobTitleId = $response->json('data.id');
    $this->app['auth']->forgetGuards();

    // 2. Update Job Title
    $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/job-titles/'.$jobTitleId, [
            'name' => 'Principal Backend Engineer',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.name', 'Principal Backend Engineer');

    $this->app['auth']->forgetGuards();

    // 3. Delete Job Title
    $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/job-titles/'.$jobTitleId);

    $deleteResponse->assertStatus(200);
    expect(JobTitle::withoutGlobalScopes()->find($jobTitleId)->deleted_at)->not->toBeNull();
})->with([
    'Owner' => UserRole::Owner,
    'Admin' => UserRole::Admin,
]);

test('manager and employee cannot create, update, or delete job titles', function (UserRole $role) {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);

    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test-token')->plainTextToken;

    $jobTitle = JobTitle::factory()->create(['name' => 'Tech Lead']);
    app(TenantContext::class)->clear();

    // View is allowed
    $viewResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/job-titles/'.$jobTitle->id);
    $viewResp->assertStatus(200);

    $this->app['auth']->forgetGuards();

    // Create is forbidden
    $createResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/job-titles', ['name' => 'Staff Eng']);
    $createResp->assertStatus(403);

    $this->app['auth']->forgetGuards();

    // Update is forbidden
    $updateResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->putJson('/api/v1/job-titles/'.$jobTitle->id, ['name' => 'VP Eng']);
    $updateResp->assertStatus(403);

    $this->app['auth']->forgetGuards();

    // Delete is forbidden
    $deleteResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/job-titles/'.$jobTitle->id);
    $deleteResp->assertStatus(403);
})->with([
    'Manager' => UserRole::Manager,
    'Employee' => UserRole::Employee,
]);

test('job title name is uniquely scoped to the company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(TenantContext::class)->set($companyA);
    $ownerA = User::factory()->owner()->create();
    $tokenA = $ownerA->createToken('token-a')->plainTextToken;
    JobTitle::factory()->create(['name' => 'HR Specialist']);
    app(TenantContext::class)->clear();

    // Duplicate in Company A rejected
    $duplicateResp = $this->withHeader('Authorization', 'Bearer '.$tokenA)
        ->postJson('/api/v1/job-titles', ['name' => 'HR Specialist']);
    $duplicateResp->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->app['auth']->forgetGuards();

    // Same title in Company B allowed
    app(TenantContext::class)->set($companyB);
    $ownerB = User::factory()->owner()->create();
    $tokenB = $ownerB->createToken('token-b')->plainTextToken;
    app(TenantContext::class)->clear();

    $responseB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
        ->postJson('/api/v1/job-titles', ['name' => 'HR Specialist']);
    $responseB->assertStatus(201)
        ->assertJsonPath('data.name', 'HR Specialist');
});

test('job title name remains permanently reserved after soft deletion', function () {
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $owner = User::factory()->owner()->create();
    $token = $owner->createToken('token')->plainTextToken;
    $jobTitle = JobTitle::factory()->create(['name' => 'Lead Architect']);
    app(TenantContext::class)->clear();

    // Soft delete the job title
    $deleteResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->deleteJson('/api/v1/job-titles/'.$jobTitle->id);
    $deleteResp->assertStatus(200);

    $this->app['auth']->forgetGuards();

    // Attempting to recreate "Lead Architect" in the same company fails validation with 422
    $recreateResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/job-titles', ['name' => 'Lead Architect']);
    $recreateResp->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});
