<?php

use App\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\Role;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Rbac\RbacService;
use App\Rbac\Registry;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const RB_OLD_HR_DELEGATES = [
    'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_personal_data',
    'departments.view', 'departments.create', 'departments.update', 'departments.delete',
    'job_titles.view', 'job_titles.create', 'job_titles.update', 'job_titles.delete',
    'roles.view', 'permissions.view',
];
const RB_OLD_HR_PERMS = [
    'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_personal_data',
    'departments.view', 'departments.create', 'departments.update', 'departments.delete',
    'job_titles.view', 'job_titles.create', 'job_titles.update', 'job_titles.delete',
    'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign', 'permissions.view', 'permissions.manage',
];

/** Create a user inside a company with an optional factory state; returns [user, token]. */
function rb_user(Company $company, string $state = 'employee', array $attrs = []): array
{
    app(TenantContext::class)->set($company);
    $f = User::factory();
    $f = match ($state) {
        'hr' => $f->hr(),
        'roleadmin' => $f,
        'owner' => $f->owner(),
        'admin' => $f->admin(),
        'sysadmin' => $f->systemAdmin(),
        'manager' => $f->manager(),
        'super' => $f,
        default => $f,
    };
    $user = $f->create($attrs + (in_array($state, ['hr', 'roleadmin'], true) ? ['role' => UserRole::Employee] : []));
    if ($state === 'roleadmin') {
        // Phase 3.3: HR is no longer a permission administrator. The RBAC mechanics tests below were written
        // with the Phase 3.2 HR as the actor, so they now use a CUSTOM-role administrator carrying exactly
        // the old HR permission set and the old delegation boundary. Assertions are unchanged.
        app(RbacService::class)->ensureSystemRoles($company);
        rb_custom_role($company, RB_OLD_HR_PERMS, RB_OLD_HR_DELEGATES, $user, 'Delegated Role Admin');
    }
    if ($state === 'super') {
        $user->is_super_admin = true;
        $user->save();
    }
    $token = $user->createToken('t')->plainTextToken;
    app(TenantContext::class)->clear();
    PermissionResolver::flush();

    return [$user, $token];
}

/** Insert a custom role directly (trusted test setup), with optional delegation boundary, and assign it. */
function rb_custom_role(Company $company, array $perms, array $delegates = [], ?User $assignTo = null, string $name = 'Custom'): Role
{
    $id = DB::table('roles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'key' => 'custom_'.strtolower($name).rand(1000, 9999),
        'name' => $name, 'is_system' => false, 'is_protected' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($perms as $p) {
        DB::table('role_permissions')->insert(['role_id' => $id, 'permission' => $p, 'created_at' => now()]);
    }
    foreach ($delegates as $p) {
        DB::table('role_delegations')->insert(['role_id' => $id, 'permission' => $p, 'created_at' => now()]);
    }
    if ($assignTo) {
        DB::table('user_roles')->insert(['company_id' => $company->id, 'user_id' => $assignTo->id, 'role_id' => $id, 'created_at' => now()]);
    }
    PermissionResolver::flush();

    return Role::withoutGlobalScopes()->findOrFail($id);
}

function rb_call($test, string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->json($method, $uri, $data);
}

function rb_hr_role_id(Company $company): int
{
    return (int) DB::table('roles')->where('company_id', $company->id)->where('key', 'hr_administrator')->value('id');
}

// ───────────────────────── baseline access ─────────────────────────

test('employee and manager roles are denied the directory and management modules (API level)', function (string $state) {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, $state);
    foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/job-titles', '/api/v1/access/roles', '/api/v1/access/users'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertStatus(403);
    }
    rb_call($this, $token, 'POST', '/api/v1/departments', ['name' => 'X'])->assertStatus(403);
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'X'])->assertStatus(403);
})->with(['employee', 'manager']);

test('legacy Owner and Admin account types hold no RBAC permission at all (no fallback)', function (string $state) {
    $company = Company::factory()->create();
    [$user, $token] = rb_user($company, $state);
    foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/job-titles', '/api/v1/access/roles', '/api/v1/audit-logs'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertStatus(403);
    }
    rb_call($this, $token, 'PUT', '/api/v1/work-schedule', ['standard_daily_hours' => 8, 'work_start_time' => '09:00', 'work_end_time' => '17:00'])->assertStatus(403);
    expect(PermissionResolver::for($user))->toBe([]);
    expect(rb_call($this, $token, 'GET', '/api/v1/auth/me')->assertOk()->json('access.permissions'))->toBe([]);
    // ordinary reads stay available to every authenticated tenant member
    rb_call($this, $token, 'GET', '/api/v1/work-schedule')->assertSuccessful();
    rb_call($this, $token, 'GET', '/api/v1/holidays')->assertOk();
})->with(['owner', 'admin']);

test('migration never elevates and public registration is gone: accounts have no user_roles and no HR permissions', function () {
    $company = Company::factory()->create();
    [$owner] = rb_user($company, 'owner');
    expect(DB::table('user_roles')->count())->toBe(0)
        ->and(PermissionResolver::for($owner))->not->toContain('employees.view');

    $this->postJson('/api/v1/auth/register-company', [
        'company_name' => 'NewCo', 'name' => 'Boss', 'email' => 'boss@newco.test', 'password' => 'Str0ng!Passw0rd#1', 'password_confirmation' => 'Str0ng!Passw0rd#1',
    ])->assertStatus(404);
    expect(DB::table('user_roles')->count())->toBe(0);
});

test('HR administrator can manage employees, departments and job titles and sees personal data', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'hr');
    rb_call($this, $token, 'POST', '/api/v1/departments', ['name' => 'Ops'])->assertCreated();
    rb_call($this, $token, 'POST', '/api/v1/job-titles', ['name' => 'Analyst'])->assertCreated();
    $r = rb_call($this, $token, 'POST', '/api/v1/employees', [
        'employee_number' => 'E1', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'e1@x.test', 'national_id' => '29001011234567', 'employment_status' => 'active',
    ]);
    $r->assertCreated();
    $id = $r->json('data.id');
    rb_call($this, $token, 'GET', '/api/v1/employees/'.$id)->assertOk()->assertJsonPath('data.national_id', '29001011234567');
    rb_call($this, $token, 'GET', '/api/v1/employees')->assertOk();
});

test('restricted HR: a role with only employees.view lists employees without personal data and cannot create', function () {
    $company = Company::factory()->create();
    [$viewer, $token] = rb_user($company, 'employee');
    rb_custom_role($company, ['employees.view'], [], $viewer, 'Viewer');
    app(TenantContext::class)->set($company);
    Employee::factory()->create(['national_id' => '29001011234567', 'phone' => '+100']);
    app(TenantContext::class)->clear();
    $list = rb_call($this, $token, 'GET', '/api/v1/employees')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('national_id')->and($list->json('data.0'))->not->toHaveKey('phone');
    rb_call($this, $token, 'POST', '/api/v1/employees', ['employee_number' => 'E2', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'e2@x.test'])->assertStatus(403);
    rb_call($this, $token, 'GET', '/api/v1/departments')->assertStatus(403);
});

test('self-service: an employee can read own record and personal data but not others', function () {
    $company = Company::factory()->create();
    [$u, $token] = rb_user($company, 'employee');
    app(TenantContext::class)->set($company);
    $own = Employee::factory()->create(['user_id' => $u->id, 'phone' => '+111']);
    $other = Employee::factory()->create(['phone' => '+222']);
    app(TenantContext::class)->clear();
    rb_call($this, $token, 'GET', '/api/v1/employees/'.$own->id)->assertOk()->assertJsonPath('data.phone', '+111');
    rb_call($this, $token, 'GET', '/api/v1/employees/'.$other->id)->assertStatus(403);
});

// ───────────────────────── tenant isolation ─────────────────────────

test('cross-tenant: HR of tenant A cannot see, edit or assign anything in tenant B', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    [, $tokenA] = rb_user($a, 'roleadmin');
    [$hrB] = rb_user($b, 'hr');
    [$plainB] = rb_user($b, 'employee');
    $roleB = rb_custom_role($b, ['employees.view'], [], null, 'BRole');
    app(TenantContext::class)->set($b);
    $empB = Employee::factory()->create();
    app(TenantContext::class)->clear();

    rb_call($this, $tokenA, 'GET', '/api/v1/employees/'.$empB->id)->assertStatus(404);
    rb_call($this, $tokenA, 'GET', '/api/v1/access/roles/'.$roleB->id)->assertStatus(404);
    rb_call($this, $tokenA, 'PUT', '/api/v1/access/roles/'.$roleB->id, ['name' => 'Hack'])->assertStatus(404);
    rb_call($this, $tokenA, 'DELETE', '/api/v1/access/roles/'.$roleB->id)->assertStatus(404);
    rb_call($this, $tokenA, 'PUT', '/api/v1/access/users/'.$plainB->id.'/roles', ['role_ids' => [$roleB->id]])->assertStatus(404);
    rb_call($this, $tokenA, 'GET', '/api/v1/access/users/'.$plainB->id.'/permissions')->assertStatus(404);
    $list = rb_call($this, $tokenA, 'GET', '/api/v1/access/users')->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->not->toContain($plainB->id)->not->toContain($hrB->id);
    // role of tenant A cannot be assigned to a user of tenant B, and DB constraints agree
    $roleA = rb_custom_role($a, ['employees.view'], [], null, 'ARole');
    rb_call($this, $tokenA, 'PUT', '/api/v1/access/users/'.$plainB->id.'/roles', ['role_ids' => [$roleA->id]])->assertStatus(404);
    expect(fn () => DB::transaction(fn () => DB::table('user_roles')->insert(['company_id' => $a->id, 'user_id' => $plainB->id, 'role_id' => $roleA->id, 'created_at' => now()])))
        ->toThrow(QueryException::class);
});

// ───────────────────────── escalation and delegation ─────────────────────────

test('protected system role cannot be modified or deleted, even by HR', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    $hrRole = rb_hr_role_id($company);
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$hrRole, ['name' => 'Renamed'])->assertStatus(403)->assertJsonPath('code', 'role_protected');
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$hrRole, ['permissions' => ['employees.view']])->assertStatus(403);
    rb_call($this, $token, 'DELETE', '/api/v1/access/roles/'.$hrRole)->assertStatus(403)->assertJsonPath('code', 'role_protected');
});

test('self-promotion is refused: HR cannot change its own assignments', function () {
    $company = Company::factory()->create();
    [$hr, $token] = rb_user($company, 'roleadmin');
    $role = rb_custom_role($company, ['employees.view'], [], null, 'X');
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$hr->id.'/roles', ['role_ids' => [rb_hr_role_id($company), $role->id]])->assertStatus(403)->assertJsonPath('code', 'self_assignment');
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$hr->id.'/roles', ['role_ids' => []])->assertStatus(403);
});

test('HR cannot delegate role administration, protected administration, reserved financial or unknown permissions', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    foreach (['roles.assign', 'permissions.manage', 'roles.update'] as $p) {
        rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'R '.$p, 'permissions' => [$p]])->assertStatus(403)->assertJsonPath('code', 'delegation_exceeded');
    }
    foreach (['audit_logs.view', 'company_settings.manage', 'payroll.view', 'overtime_factors.manage', 'platform.admin', 'nope'] as $p) {
        rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'R '.$p, 'permissions' => [$p]])->assertStatus(422);
    }
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Ok', 'permissions' => ['employees.view', 'departments.view']])->assertCreated();
});

test('holding a permission does not imply the right to delegate it (explicit boundary)', function () {
    $company = Company::factory()->create();
    [$u, $token] = rb_user($company, 'employee');
    // holds roles.create, permissions.manage and employees.view but has NO delegation boundary
    rb_custom_role($company, ['roles.create', 'permissions.manage', 'employees.view', 'roles.view'], [], $u, 'Holder');
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Copy', 'permissions' => ['employees.view']])->assertStatus(403)->assertJsonPath('code', 'delegation_exceeded');
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Empty'])->assertCreated();
});

test('indirect escalation: editing a role used to gain permissions, or stripping a stronger role, is refused', function () {
    $company = Company::factory()->create();
    [$u, $token] = rb_user($company, 'employee');
    $role = rb_custom_role($company, ['roles.update', 'permissions.manage', 'roles.view'], ['employees.view'], $u, 'Editor');
    rb_custom_role($company, ['employees.view'], [], $u, 'HeldElsewhere'); // held, and inside the boundary
    // the actor's own role cannot be extended with permissions outside the boundary
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$role->id, ['permissions' => ['roles.update', 'permissions.manage', 'roles.view', 'employees.delete']])->assertStatus(403)->assertJsonPath('code', 'delegation_exceeded');
    // inside the boundary but on a role assigned to the actor: self escalation
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$role->id, ['permissions' => ['roles.update', 'permissions.manage', 'roles.view', 'employees.view']])->assertStatus(403)->assertJsonPath('code', 'self_escalation');
    expect(DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->sort()->values()->all())->toBe(['permissions.manage', 'roles.update', 'roles.view']);
});

test('HR cannot strip or grant a role that carries more than its own delegation scope (bulk assignment included)', function () {
    $company = Company::factory()->create();
    [$hr, $token] = rb_user($company, 'roleadmin');
    [$other] = rb_user($company, 'hr');   // second HR administrator (trusted bootstrap)
    $hrRole = rb_hr_role_id($company);
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$other->id.'/roles', ['role_ids' => []])->assertStatus(403)->assertJsonPath('code', 'delegation_exceeded');
    [$plain] = rb_user($company, 'employee');
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$plain->id.'/roles', ['role_ids' => [$hrRole]])->assertStatus(403)->assertJsonPath('code', 'delegation_exceeded');
    expect(DB::table('user_roles')->where('user_id', $plain->id)->count())->toBe(0);
});

test('HR can create a role inside its boundary, assign it, inspect effective permissions and remove it safely', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    [$plain] = rb_user($company, 'employee');
    $created = rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Dept Clerk', 'permissions' => ['departments.view', 'departments.create']])->assertCreated();
    $rid = $created->json('data.id');
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$plain->id.'/roles', ['role_ids' => [$rid]])->assertOk()
        ->assertJsonPath('data.effective_permissions', ['departments.create', 'departments.view']);
    rb_call($this, $token, 'DELETE', '/api/v1/access/roles/'.$rid)->assertStatus(409)->assertJsonPath('code', 'role_in_use');
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$plain->id.'/roles', ['role_ids' => []])->assertOk();
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$rid, ['name' => 'Clerk2', 'permissions' => ['departments.view']])->assertOk()->assertJsonPath('data.permissions', ['departments.view']);
    rb_call($this, $token, 'DELETE', '/api/v1/access/roles/'.$rid)->assertOk();
    rb_call($this, $token, 'GET', '/api/v1/access/users/'.$plain->id.'/permissions')->assertOk()->assertJsonPath('data.effective_permissions', []);
});

// ───────────────────────── super admin ─────────────────────────

test('platform Super Admin has no implicit tenant permissions, is not listed or assignable, and cannot be created through role APIs', function () {
    $company = Company::factory()->create();
    [$super, $superToken] = rb_user($company, 'super', ['role' => UserRole::Owner]);
    [, $hrToken] = rb_user($company, 'roleadmin');
    expect(PermissionResolver::for($super))->toBe([]);
    foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/access/roles', '/api/v1/audit-logs'] as $uri) {
        rb_call($this, $superToken, 'GET', $uri)->assertStatus(403);
    }
    $ids = collect(rb_call($this, $hrToken, 'GET', '/api/v1/access/users')->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($super->id);
    rb_call($this, $hrToken, 'PUT', '/api/v1/access/users/'.$super->id.'/roles', ['role_ids' => [rb_hr_role_id($company)]])->assertStatus(404);
    rb_call($this, $hrToken, 'GET', '/api/v1/access/users/'.$super->id.'/permissions')->assertStatus(404);
    // mass-assignment attempts are ignored
    [$plain] = rb_user($company, 'employee');
    rb_call($this, $hrToken, 'POST', '/api/v1/access/roles', ['name' => 'Sneaky', 'is_super_admin' => true, 'is_system' => true, 'is_protected' => true, 'company_id' => 999])->assertCreated()
        ->assertJsonPath('data.is_system', false)->assertJsonPath('data.is_protected', false);
    expect(User::withoutGlobalScopes()->where('is_super_admin', true)->count())->toBe(1);
    // a super admin account in the same tenant never gains tenant permissions even if a role row exists
    DB::table('user_roles')->insert(['company_id' => $company->id, 'user_id' => $super->id, 'role_id' => rb_hr_role_id($company), 'created_at' => now()]);
    PermissionResolver::flush();
    expect(PermissionResolver::for($super))->toBe([]);
});

test('super admin tenant support: explicit workflow id, audited under the affected tenant, no implicit tenant access', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    [$super, $token] = rb_user($other, 'super', ['role' => UserRole::Owner]);
    app(TenantContext::class)->set($company);
    $employee = Employee::factory()->create();
    $request = PermissionRequest::factory()->create(['employee_id' => $employee->id]);
    $workflow = ApprovalWorkflow::create(['approvable_type' => $request::class, 'approvable_id' => $request->id, 'status' => 'pending', 'current_step_order' => 1, 'total_steps' => 1]);
    app(TenantContext::class)->clear();

    // missing workflow: 404 and no misleading audit row
    $before = AuditLog::withoutGlobalScopes()->count();
    rb_call($this, $token, 'POST', '/api/v1/super-admin/override-workflow', ['workflow_id' => 999999, 'decision' => 'reject', 'reason' => 'support case 41 missing id'])->assertStatus(404);
    expect(AuditLog::withoutGlobalScopes()->count())->toBe($before);

    // real override: explicit id, reason recorded, audited under the AFFECTED tenant (not the super admin's home tenant)
    rb_call($this, $token, 'POST', '/api/v1/super-admin/override-workflow', ['workflow_id' => $workflow->id, 'decision' => 'reject', 'reason' => 'support case 42: customer asked to close'])->assertOk();
    $audit = AuditLog::withoutGlobalScopes()->where('action', 'super_admin.workflow_override')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->company_id)->toBe($company->id)
        ->and($audit[0]->user_id)->toBe($super->id)
        ->and($audit[0]->new_values['decision'])->toBe('reject')
        ->and($audit[0]->new_values['reason'])->toBe('support case 42: customer asked to close');
    expect(PermissionRequest::withoutGlobalScopes()->find($request->id)->status)->toBe('rejected');

    // the mechanism grants no ambient access: tenant data endpoints stay closed for the same token
    foreach (['/api/v1/employees', '/api/v1/access/roles', '/api/v1/audit-logs'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertStatus(403);
    }
    // and an ordinary tenant HR user cannot use it
    [, $hrToken] = rb_user($company, 'hr');
    rb_call($this, $hrToken, 'POST', '/api/v1/super-admin/override-workflow', ['workflow_id' => $workflow->id, 'decision' => 'approve'])->assertStatus(403);
});

// ───────────────────────── sessions, revocation, last admin ─────────────────────────

test('revoking a role takes effect on the very next request with the same token', function () {
    $company = Company::factory()->create();
    [$hr, $token] = rb_user($company, 'hr');
    rb_call($this, $token, 'GET', '/api/v1/employees')->assertOk();
    DB::table('user_roles')->where('user_id', $hr->id)->delete();
    rb_call($this, $token, 'GET', '/api/v1/employees')->assertStatus(403);
    rb_call($this, $token, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('access.permissions', []);
});

test('/auth/me returns server-verified permissions, roles and no-store caching', function () {
    $company = Company::factory()->create();
    [$hr, $token] = rb_user($company, 'hr');
    $res = rb_call($this, $token, 'GET', '/api/v1/auth/me')->assertOk();
    expect($res->json('access.permissions'))->toContain('employees.view', 'accounts.reset_password', 'audit_logs.view_operational')
        ->and($res->json('access.permissions'))->not->toContain('roles.assign', 'roles.view', 'permissions.manage', 'permissions.view', 'audit_logs.view')
        ->and($res->json('access.roles.0.key'))->toBe('hr_administrator')
        ->and($res->json('access.is_platform_admin'))->toBeFalse()
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');
    expect($res->json('user'))->not->toHaveKey('password');
});

test('last permission administrator is protected, also for sequential conflicting changes', function () {
    $company = Company::factory()->create();
    [$x] = rb_user($company, 'employee');
    $role = rb_custom_role($company, ['roles.update', 'permissions.manage', 'roles.assign', 'roles.view'], ['roles.assign'], $x, 'OnlyAdmin');
    $service = app(RbacService::class);
    app(TenantContext::class)->set($company);
    // X is the only administrator (roles.assign + permissions.manage). Removing roles.assign from their role must fail.
    try {
        $service->updateRole($x, Role::findOrFail($role->id), ['permissions' => ['roles.update', 'permissions.manage', 'roles.view']]);
        $this->fail('expected last_administrator');
    } catch (RbacDenied $e) {
        expect($e->errorCode)->toBe('last_administrator')->and($e->status)->toBe(409);
    }
    app(TenantContext::class)->clear();
    expect(DB::table('role_permissions')->where('role_id', $role->id)->count())->toBe(4);
});

test('last administrator: unassigning the only administrator through the API is refused', function () {
    $company = Company::factory()->create();
    [$a, $tokenA] = rb_user($company, 'hr');
    [$other, $otherToken] = rb_user($company, 'employee');
    // other gets a role that lets it assign roles within a boundary that covers the HR role (trusted setup)
    $hrRole = rb_hr_role_id($company);
    rb_custom_role($company, ['roles.assign', 'roles.view', 'permissions.view', 'permissions.manage'], ['roles.assign', 'permissions.manage', 'roles.view', 'permissions.view', 'employees.view'], $other, 'Assigner');
    // other is an administrator too, so removing HR from A leaves one; then removing others' role would leave none.
    rb_call($this, $otherToken, 'PUT', '/api/v1/access/users/'.$a->id.'/roles', ['role_ids' => []])->assertStatus(403); // HR role carries more than the boundary
    expect(DB::table('user_roles')->where('user_id', $a->id)->where('role_id', $hrRole)->exists())->toBeTrue();
});

// ───────────────────────── audit ─────────────────────────

test('RBAC changes are audited with actor, tenant, previous and new state and no secrets', function () {
    $company = Company::factory()->create();
    rb_user($company, 'hr');
    [$hr, $token] = rb_user($company, 'roleadmin');
    [$plain] = rb_user($company, 'employee');
    $rid = rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Aud', 'permissions' => ['employees.view']])->assertCreated()->json('data.id');
    rb_call($this, $token, 'PUT', '/api/v1/access/roles/'.$rid, ['permissions' => ['employees.view', 'departments.view']])->assertOk();
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$plain->id.'/roles', ['role_ids' => [$rid]])->assertOk();
    rb_call($this, $token, 'PUT', '/api/v1/access/users/'.$plain->id.'/roles', ['role_ids' => []])->assertOk();
    rb_call($this, $token, 'DELETE', '/api/v1/access/roles/'.$rid)->assertOk();
    $logs = AuditLog::withoutGlobalScopes()->where('company_id', $company->id)->where('action', 'like', 'rbac.%')->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toContain('rbac.bootstrap_hr', 'rbac.role.created', 'rbac.role.updated', 'rbac.user_roles.updated', 'rbac.role.deleted');
    $updated = $logs->firstWhere('action', 'rbac.role.updated');
    expect($updated->user_id)->toBe($hr->id)->and($updated->company_id)->toBe($company->id)
        ->and($updated->old_values['permissions'])->toBe(['employees.view'])
        ->and($updated->new_values['permissions'])->toBe(['departments.view', 'employees.view']);
    expect(json_encode($logs->toArray()))->not->toContain('password')->not->toContain('plainTextToken');
});

// ───────────────────────── bootstrap command and migration ─────────────────────────

test('bootstrap command: explicit, audited, refuses wrong tenant, platform and inactive accounts, dry-run changes nothing', function () {
    $a = Company::factory()->create(['slug' => 'acme']);
    $b = Company::factory()->create();
    [$owner] = rb_user($a, 'owner', ['email' => 'owner@acme.test']);
    [$super] = rb_user($a, 'super', ['email' => 'root@acme.test', 'role' => UserRole::Owner]);
    [$inactive] = rb_user($a, 'employee', ['email' => 'off@acme.test', 'is_active' => false]);
    [$foreign] = rb_user($b, 'employee', ['email' => 'f@b.test']);

    expect(Artisan::call('rbac:bootstrap-hr', ['--company' => 'acme', '--email' => 'owner@acme.test', '--dry-run' => true]))->toBe(0);
    expect(DB::table('user_roles')->count())->toBe(0);
    foreach (['root@acme.test', 'off@acme.test', 'f@b.test', 'missing@acme.test'] as $email) {
        expect(Artisan::call('rbac:bootstrap-hr', ['--company' => 'acme', '--email' => $email]))->toBe(1);
    }
    expect(Artisan::call('rbac:bootstrap-hr', ['--email' => 'owner@acme.test']))->toBe(1);
    expect(DB::table('user_roles')->count())->toBe(0);

    expect(Artisan::call('rbac:bootstrap-hr', ['--company' => 'acme', '--email' => 'owner@acme.test']))->toBe(0);
    expect(Artisan::call('rbac:bootstrap-hr', ['--company' => (string) $a->id, '--email' => 'owner@acme.test']))->toBe(0); // idempotent
    PermissionResolver::flush();
    expect(DB::table('user_roles')->count())->toBe(1)->and(PermissionResolver::for($owner->fresh()))->toContain('employees.view', 'accounts.reset_password', 'audit_logs.view_operational')
        ->and(PermissionResolver::for($owner->fresh()))->not->toContain('roles.assign', 'permissions.manage');
    expect(AuditLog::withoutGlobalScopes()->where('action', 'rbac.bootstrap_hr')->where('company_id', $a->id)->count())->toBe(1);
    // the existing tenant recovers management access with its own token after bootstrap
    $token = $owner->createToken('x')->plainTextToken;
    rb_call($this, $token, 'GET', '/api/v1/employees')->assertOk();
});

test('rbac migration down removes only the new tables and leaves users intact; up is repeatable', function () {
    $company = Company::factory()->create();
    [$user] = rb_user($company, 'hr');
    $migration = require base_path('database/migrations/2026_11_26_100000_create_rbac_tables.php');
    $migration->down();
    expect(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasTable('user_roles'))->toBeFalse()
        ->and(User::withoutGlobalScopes()->whereKey($user->id)->exists())->toBeTrue();
    $migration->up();
    expect(Schema::hasTable('roles'))->toBeTrue()
        ->and(DB::table('user_roles')->count())->toBe(0);
});

test('role names are unique per tenant and the same name may exist in another tenant', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    [, $ta] = rb_user($a, 'roleadmin');
    [, $tb] = rb_user($b, 'roleadmin');
    rb_call($this, $ta, 'POST', '/api/v1/access/roles', ['name' => 'Clerk'])->assertCreated();
    rb_call($this, $ta, 'POST', '/api/v1/access/roles', ['name' => 'Clerk'])->assertStatus(422);
    rb_call($this, $tb, 'POST', '/api/v1/access/roles', ['name' => 'Clerk'])->assertCreated();
});

test('dashboard-relevant: personal data and counts do not leak through restricted APIs', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'employee');
    app(TenantContext::class)->set($company);
    Department::factory()->count(3)->create();
    Employee::factory()->count(3)->create();
    app(TenantContext::class)->clear();
    foreach (['/api/v1/employees', '/api/v1/departments'] as $uri) {
        $r = rb_call($this, $token, 'GET', $uri)->assertStatus(403);
        expect($r->json())->not->toHaveKey('data')->not->toHaveKey('meta');
    }
});

test('role name uniqueness is enforced inside the service lock, not only by request validation (race regression)', function () {
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    $svc = app(RbacService::class);
    app(TenantContext::class)->set($company);
    $svc->createRole($hr, ['name' => 'Clerk']);
    expect(fn () => $svc->createRole($hr, ['name' => 'Clerk']))->toThrow(RbacDenied::class);
    $other = $svc->createRole($hr, ['name' => 'Other']);
    expect(fn () => $svc->updateRole($hr, $other, ['name' => 'Clerk']))->toThrow(RbacDenied::class);
    app(TenantContext::class)->clear();
});

test('role names are unique per tenant case-insensitively, enforced by the service AND by a database unique index', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Payroll Clerk'])->assertCreated();
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'payroll clerk'])->assertStatus(422);
    // bypass the service entirely: the index itself must refuse a case-variant duplicate in the same tenant
    $row = fn ($cid, $name, $key) => ['uuid' => (string) Str::uuid(), 'company_id' => $cid, 'key' => $key, 'name' => $name, 'is_system' => false, 'is_protected' => false, 'created_at' => now(), 'updated_at' => now()];
    expect(function () use ($company, $row) {
        DB::beginTransaction();
        try {
            DB::table('roles')->insert($row($company->id, 'PAYROLL CLERK', 'raw_dup'));
        } finally {
            DB::rollBack();
        }
    })->toThrow(QueryException::class);
    // another tenant may use the same name
    DB::table('roles')->insert($row($other->id, 'Payroll Clerk', 'other_tenant'));
    expect(DB::table('roles')->where('company_id', $other->id)->where('key', 'other_tenant')->exists())->toBeTrue();
});

// ───────────────────────── FINAL-V2 ─────────────────────────

/** A stale resolver memo makes the PRE-lock check pass; the permission is then revoked in the database. The post-lock recheck must refuse. */
function rb_revoke_while_waiting(User $actor, string $permission, ?callable $also = null): void
{
    expect(PermissionResolver::has($actor, $permission))->toBeTrue(); // warm: pre-lock check will pass
    DB::table('role_permissions')->whereIn('role_id', DB::table('user_roles')->where('user_id', $actor->id)->pluck('role_id'))
        ->where('permission', $permission)->delete();
}

test('TOCTOU: a permission revoked while a mutation waits for the tenant lock is honoured (create, empty-permission create, metadata update, delete, assign)', function () {
    $svc = app(RbacService::class);
    foreach ([
        'roles.create' => fn ($hr, $c) => $svc->createRole($hr, ['name' => 'Empty Role']),
        'permissions.manage' => fn ($hr, $c) => $svc->createRole($hr, ['name' => 'With Perms', 'permissions' => ['employees.view']]),
    ] as $perm => $call) {
        $company = Company::factory()->create();
        [$hr] = rb_user($company, 'roleadmin');
        app(TenantContext::class)->set($company);
        rb_revoke_while_waiting($hr, $perm);
        expect($call)->not->toBeNull();
        expect(fn () => $call($hr, $company))->toThrow(RbacDenied::class);
        expect(DB::table('roles')->where('company_id', $company->id)->whereIn('name', ['Empty Role', 'With Perms'])->count())->toBe(0);
        app(TenantContext::class)->clear();
    }

    // metadata-only update (no permissions key): roles.update revoked
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    app(TenantContext::class)->set($company);
    $role = $svc->createRole($hr, ['name' => 'Editable']);
    PermissionResolver::flush();
    rb_revoke_while_waiting($hr, 'roles.update');
    expect(fn () => $svc->updateRole($hr, $role, ['description' => 'changed']))->toThrow(RbacDenied::class);
    expect(Role::withoutGlobalScopes()->find($role->id)->description)->toBeNull();
    app(TenantContext::class)->clear();

    // update with permissions: permissions.manage revoked though roles.update is still held
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    app(TenantContext::class)->set($company);
    $role = $svc->createRole($hr, ['name' => 'Editable']);
    PermissionResolver::flush();
    rb_revoke_while_waiting($hr, 'permissions.manage');
    expect(fn () => $svc->updateRole($hr, $role, ['permissions' => ['employees.view']]))->toThrow(RbacDenied::class);
    expect(DB::table('role_permissions')->where('role_id', $role->id)->count())->toBe(0);
    app(TenantContext::class)->clear();

    // delete
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    app(TenantContext::class)->set($company);
    $role = $svc->createRole($hr, ['name' => 'Doomed']);
    PermissionResolver::flush();
    rb_revoke_while_waiting($hr, 'roles.delete');
    expect(fn () => $svc->deleteRole($hr, $role))->toThrow(RbacDenied::class);
    expect(Role::withoutGlobalScopes()->find($role->id))->not->toBeNull();
    app(TenantContext::class)->clear();

    // assignment
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    [$emp] = rb_user($company, 'employee');
    app(TenantContext::class)->set($company);
    $role = $svc->createRole($hr, ['name' => 'Viewer']);
    PermissionResolver::flush();
    rb_revoke_while_waiting($hr, 'roles.assign');
    expect(fn () => $svc->syncUserRoles($hr, $emp, [$role->id]))->toThrow(RbacDenied::class);
    expect(DB::table('user_roles')->where('user_id', $emp->id)->count())->toBe(0);
    app(TenantContext::class)->clear();
});

test('TOCTOU: an actor deactivated while waiting for the lock is refused', function () {
    $company = Company::factory()->create();
    [$hr] = rb_user($company, 'roleadmin');
    app(TenantContext::class)->set($company);
    expect(PermissionResolver::has($hr, 'roles.create'))->toBeTrue();
    User::withoutGlobalScopes()->whereKey($hr->id)->update(['is_active' => false]);
    expect(fn () => app(RbacService::class)->createRole($hr, ['name' => 'Ghost']))->toThrow(RbacDenied::class);
    app(TenantContext::class)->clear();
});

test('bootstrapped Owner and Admin hold exactly the HR Administrator permissions, no RBAC administration and no full audit access', function (string $state) {
    $company = Company::factory()->create();
    [$user, $token] = rb_user($company, $state);
    // before bootstrap: nothing (no legacy fallback)
    expect(PermissionResolver::for($user))->toBe([]);
    Artisan::call('rbac:bootstrap-hr', ['--company' => $company->id, '--email' => $user->email]);
    PermissionResolver::flush();
    $hrPerms = DB::table('role_permissions')->where('role_id', rb_hr_role_id($company))->pluck('permission')->sort()->values()->all();
    expect(PermissionResolver::for($user->refresh()))->toBe($hrPerms);
    $me = rb_call($this, $token, 'GET', '/api/v1/auth/me')->assertOk()->json('access.permissions');
    // Phase 3.3 revised matrix: operational administration yes; roles & permissions and the full audit trail no
    expect($me)->toContain('company_settings.manage', 'work_schedules.manage', 'audit_logs.view_operational', 'approval_workflows.manage')
        ->not->toContain('audit_logs.view')->not->toContain('roles.view')->not->toContain('roles.assign')->not->toContain('permissions.manage')->not->toContain('permissions.view');
    // the API is closed too, not only the navigation
    foreach (['/api/v1/access/roles', '/api/v1/access/users'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertStatus(403);
    }
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => 'Escalate', 'permissions' => ['employees.view']])->assertStatus(403);
    rb_call($this, $token, 'GET', '/api/v1/audit-logs')->assertOk();
    rb_call($this, $token, 'PUT', '/api/v1/work-schedule', ['standard_daily_hours' => 8, 'work_start_time' => '09:00', 'work_end_time' => '17:00'])->assertSuccessful();
    rb_call($this, $token, 'POST', '/api/v1/holidays', ['name' => 'X', 'start_date' => '2030-01-01', 'end_date' => '2030-01-01'])->assertStatus(201);
})->with(['owner', 'admin']);

test('removing every role from a bootstrapped Owner or Admin leaves zero privileges, whatever the history', function (string $state) {
    $company = Company::factory()->create();
    [$user, $token] = rb_user($company, $state);
    [$other] = rb_user($company, $state === 'owner' ? 'admin' : 'owner', ['email' => 'second.'.$state.'@example.test']);
    expect(PermissionResolver::for($other))->toBe([]);
    Artisan::call('rbac:bootstrap-hr', ['--company' => $company->id, '--email' => $user->email]);
    Artisan::call('rbac:bootstrap-hr', ['--company' => $company->id, '--email' => 'second.'.$state.'@example.test']);
    PermissionResolver::flush();
    DB::table('user_roles')->where('user_id', $user->id)->delete();
    PermissionResolver::flush();
    expect(PermissionResolver::for($user->refresh()))->toBe([]);
    foreach (['/api/v1/audit-logs', '/api/v1/access/roles'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertStatus(403);
    }
    rb_call($this, $token, 'PUT', '/api/v1/work-schedule', ['standard_daily_hours' => 8, 'work_start_time' => '09:00', 'work_end_time' => '17:00'])->assertStatus(403);
    // audit history is irrelevant: archiving or deleting it cannot create or restore authority
    DB::table('audit_logs')->where('company_id', $company->id)->delete();
    PermissionResolver::flush();
    expect(PermissionResolver::for($user->refresh()))->toBe([]);
    rb_call($this, $token, 'GET', '/api/v1/audit-logs')->assertStatus(403);
    // remove everything from the tenant, including the second account: still nothing, nobody regains anything
    DB::table('user_roles')->where('company_id', $company->id)->delete();
    PermissionResolver::flush();
    expect(PermissionResolver::for($other->refresh()))->toBe([]);
})->with(['owner', 'admin']);

test('maximum-length English and Arabic role names create successfully with a bounded internal key', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    $en = str_repeat('Payroll Clerk ', 10);
    $en = mb_substr($en, 0, 100);
    $ar = mb_substr(str_repeat('مسؤول الرواتب ', 10), 0, 100);
    foreach ([$en, $ar, mb_substr(str_repeat('é', 120), 0, 100).''] as $i => $name) {
        $r = rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => $name, 'name_ar' => $ar])->assertCreated();
        expect(strlen($r->json('data.key')))->toBeLessThanOrEqual(80);
    }
    expect(DB::table('roles')->where('company_id', $company->id)->where('key', 'like', 'custom_%')->where('name', '!=', 'Delegated Role Admin')->distinct()->count('key'))->toBe(3);
    rb_call($this, $token, 'POST', '/api/v1/access/roles', ['name' => str_repeat('x', 101)])->assertStatus(422);
});

test('access user directory: tenant-scoped server-side search and pagination with accurate totals', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    [, $token] = rb_user($company, 'roleadmin');
    rb_user($other, 'employee', ['name' => 'Zed Outsider']);
    app(TenantContext::class)->set($company);
    User::factory()->count(59)->create();
    User::factory()->create(['name' => 'Needle Person', 'email' => 'needle.person@example.test']);
    User::factory()->create(['name' => '100% Match_x', 'email' => 'pct@example.test']);
    app(TenantContext::class)->clear();
    $all = rb_call($this, $token, 'GET', '/api/v1/access/users?per_page=25&page=1')->assertOk();
    expect($all->json('meta.total'))->toBe(62)->and($all->json('meta.last_page'))->toBe(3)->and(count($all->json('data')))->toBe(25);
    $p3 = rb_call($this, $token, 'GET', '/api/v1/access/users?per_page=25&page=3')->assertOk();
    expect(count($p3->json('data')))->toBe(12);
    $hit = rb_call($this, $token, 'GET', '/api/v1/access/users?search=needle')->assertOk();
    expect($hit->json('meta.total'))->toBe(1)->and($hit->json('data.0.email'))->toBe('needle.person@example.test');
    expect(rb_call($this, $token, 'GET', '/api/v1/access/users?search=outsider')->json('meta.total'))->toBe(0);
    expect(rb_call($this, $token, 'GET', '/api/v1/access/users?search=100%25')->json('meta.total'))->toBe(1); // wildcard characters are literal
    rb_call($this, $token, 'GET', '/api/v1/access/users?per_page=1000')->assertStatus(422);
    $ids = collect(range(1, 3))->flatMap(fn ($p) => collect(rb_call($this, $token, 'GET', "/api/v1/access/users?per_page=25&page=$p")->json('data'))->pluck('id'))->all();
    expect(count($ids))->toBe(62)->and(count(array_unique($ids)))->toBe(62);
});

test('super admin override requires a meaningful reason and records it in the audit event', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'super');
    foreach ([null, '', 'short', '              '] as $bad) {
        $payload = ['workflow_id' => 1, 'decision' => 'approve'] + ($bad === null ? [] : ['reason' => $bad]);
        rb_call($this, $token, 'POST', '/api/v1/super-admin/override-workflow', $payload)->assertStatus(422);
    }
});

test('super admin override is atomic with its audit record: an audit failure rolls the mutation back', function () {
    $company = Company::factory()->create();
    [, $token] = rb_user($company, 'super');
    app(TenantContext::class)->set($company);
    $employee = Employee::factory()->create();
    $request = PermissionRequest::factory()->create(['employee_id' => $employee->id]);
    $workflow = ApprovalWorkflow::create(['approvable_type' => $request::class, 'approvable_id' => $request->id, 'status' => 'pending', 'current_step_order' => 1, 'total_steps' => 1]);
    app(TenantContext::class)->clear();
    $statusBefore = PermissionRequest::withoutGlobalScopes()->find($request->id)->status;

    app()->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function log(string $action, ?Model $auditable = null, ?array $oldValues = null, ?array $newValues = null, ?User $user = null, ?string $reason = null, ?string $visibility = null): ?AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => rb_call($this, $token, 'POST', '/api/v1/super-admin/override-workflow', ['workflow_id' => $workflow->id, 'decision' => 'reject', 'reason' => 'support case 77: atomicity check']))->toThrow(RuntimeException::class);
    expect(ApprovalWorkflow::withoutGlobalScopes()->find($workflow->id)->status)->toBe('pending')
        ->and(PermissionRequest::withoutGlobalScopes()->find($request->id)->status)->toBe($statusBefore);
});

// ─────────────────────────── FINAL-V3: tenant System Super Admin, trusted procedures ───────────────────────────

function rb_cmd(string $command, array $args): array
{
    $code = Artisan::call($command, $args);

    return [$code, Artisan::output()];
}

const RB_REASON = 'Authorized provisioning for the tenant owner, ticket 1001';

test('System Super Admin holds a superset of HR Administrator plus the protected tenant administration permissions, nothing platform-level', function () {
    $company = Company::factory()->create();
    [$sa, $token] = rb_user($company, 'sysadmin');
    $hrPerms = Registry::systemRole('hr_administrator')['permissions'];
    $perms = PermissionResolver::for($sa);
    expect(array_diff($hrPerms, $perms))->toBe([]);
    expect($perms)->toContain('audit_logs.view')->toContain('company_settings.manage')->toContain('work_schedules.manage');
    expect($perms)->not->toContain('payroll.view')->not->toContain('payroll.manage');
    expect(rb_call($this, $token, 'GET', '/api/v1/auth/me')->json('access.is_platform_admin'))->toBeFalse();
    foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/job-titles', '/api/v1/access/roles', '/api/v1/audit-logs', '/api/v1/work-schedule', '/api/v1/holidays'] as $uri) {
        rb_call($this, $token, 'GET', $uri)->assertSuccessful();
    }
    rb_call($this, $token, 'PUT', '/api/v1/work-schedule', ['standard_daily_hours' => 8, 'work_start_time' => '09:00', 'work_end_time' => '17:00'])->assertSuccessful();
    rb_call($this, $token, 'POST', '/api/v1/holidays', ['name' => 'Founders Day', 'start_date' => '2031-02-01', 'end_date' => '2031-02-01'])->assertStatus(201);
    // no platform authority
    rb_call($this, $token, 'GET', '/api/v1/super-admin/tenants')->assertStatus(403);
});

test('System Super Admin is tenant-isolated: another tenant is invisible and untouchable', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    [, $token] = rb_user($a, 'sysadmin');
    [$bUser] = rb_user($b, 'employee', ['name' => 'Beta Person', 'email' => 'beta.person@example.test']);
    $search = rb_call($this, $token, 'GET', '/api/v1/access/users?search=beta')->assertOk();
    expect($search->json('meta.total'))->toBe(0);
    rb_call($this, $token, 'GET', "/api/v1/access/users/{$bUser->id}/permissions")->assertStatus(404);
    rb_call($this, $token, 'PUT', "/api/v1/access/users/{$bUser->id}/roles", ['role_ids' => []])->assertStatus(404);
    // tenant B has no System Super Admin: tenant A's holder gives it nothing
    expect(PermissionResolver::for($bUser))->toBe([]);
    expect(DB::table('audit_logs')->where('company_id', $b->id)->count())->toBe(0);
});

test('System Super Admin manages HR Administrator assignments through the audited tenant API; HR cannot', function () {
    $company = Company::factory()->create();
    [$sa, $saToken] = rb_user($company, 'sysadmin');
    [$hr, $hrToken] = rb_user($company, 'hr', ['email' => 'hr.one@example.test']);
    [$emp] = rb_user($company, 'employee', ['email' => 'emp.one@example.test']);
    $hrRole = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'hr_administrator')->firstOrFail();
    // SSA assigns the HR role
    rb_call($this, $saToken, 'PUT', "/api/v1/access/users/{$emp->id}/roles", ['role_ids' => [$hrRole->id]])->assertOk();
    expect(DB::table('user_roles')->where('user_id', $emp->id)->where('role_id', $hrRole->id)->exists())->toBeTrue();
    expect(AuditLog::withoutGlobalScopes()->where('company_id', $company->id)->where('action', 'rbac.user_roles.updated')->exists())->toBeTrue();
    // HR cannot hand out or strip HR administration (outside its delegation boundary)
    [$emp2] = rb_user($company, 'employee', ['email' => 'emp.two@example.test']);
    rb_call($this, $hrToken, 'PUT', "/api/v1/access/users/{$emp2->id}/roles", ['role_ids' => [$hrRole->id]])->assertStatus(403);
    rb_call($this, $hrToken, 'PUT', "/api/v1/access/users/{$emp->id}/roles", ['role_ids' => []])->assertStatus(403);
    // SSA removes it again
    rb_call($this, $saToken, 'PUT', "/api/v1/access/users/{$emp->id}/roles", ['role_ids' => []])->assertOk();
});

test('the System Super Admin role and account are protected from every ordinary API path', function () {
    $company = Company::factory()->create();
    [$sa, $saToken] = rb_user($company, 'sysadmin');
    [$hr, $hrToken] = rb_user($company, 'hr', ['email' => 'hr.two@example.test']);
    [$emp] = rb_user($company, 'employee', ['email' => 'emp.three@example.test']);
    $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'system_super_admin')->firstOrFail();
    expect((bool) $role->is_system)->toBeTrue()->and((bool) $role->is_protected)->toBeTrue();
    // nobody can assign it through the API, including the System Super Admin itself and HR
    foreach ([$saToken, $hrToken] as $t) {
        rb_call($this, $t, 'PUT', "/api/v1/access/users/{$emp->id}/roles", ['role_ids' => [$role->id]])->assertStatus(403);
    }
    expect(DB::table('user_roles')->where('role_id', $role->id)->count())->toBe(1);
    // HR cannot demote or modify the protected administrator
    rb_call($this, $hrToken, 'PUT', "/api/v1/access/users/{$sa->id}/roles", ['role_ids' => []])->assertStatus(403);
    // another account of the same rank cannot touch it either; the holder cannot change itself
    rb_call($this, $saToken, 'PUT', "/api/v1/access/users/{$sa->id}/roles", ['role_ids' => []])->assertStatus(403);
    // role definition is immutable and undeletable through ordinary role editing
    rb_call($this, $saToken, 'PUT', "/api/v1/access/roles/{$role->id}", ['name' => 'Renamed'])->assertStatus(403);
    rb_call($this, $saToken, 'DELETE', "/api/v1/access/roles/{$role->id}")->assertStatus(403);
    // a custom role can never carry protected permissions
    rb_call($this, $saToken, 'POST', '/api/v1/access/roles', ['name' => 'Sneaky', 'permissions' => ['audit_logs.view']])->assertStatus(422);
    expect(DB::table('role_permissions')->where('permission', 'audit_logs.view')->count())->toBe(1);
    // the protected account is still intact and the platform account type cannot be targeted
    expect(PermissionResolver::for($sa->refresh()))->toContain('audit_logs.view');
});

test('provisioning: dry run changes nothing, real run is audited with the reason, repeat is idempotent', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$user] = rb_user($company, 'owner', ['email' => 'dev@example.test']);
    [$code, $out] = rb_cmd('rbac:bootstrap-system-admin', ['--company' => 'acme-v3', '--email' => 'dev@example.test', '--reason' => RB_REASON, '--dry-run' => true]);
    expect($code)->toBe(0)->and($out)->toContain('Dry run');
    expect(DB::table('user_roles')->count())->toBe(0)->and(DB::table('roles')->where('key', 'system_super_admin')->count())->toBe(0);
    [$code, $out] = rb_cmd('rbac:bootstrap-system-admin', ['--company' => 'acme-v3', '--email' => 'dev@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('assigned');
    PermissionResolver::flush();
    expect(PermissionResolver::for($user->refresh()))->toContain('audit_logs.view');
    $audit = AuditLog::withoutGlobalScopes()->where('action', 'rbac.bootstrap_system_admin')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->new_values['reason'])->toBe(RB_REASON)->and($audit[0]->company_id)->toBe($company->id);
    [$code, $out] = rb_cmd('rbac:bootstrap-system-admin', ['--company' => 'acme-v3', '--email' => 'dev@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('idempotent');
    expect(DB::table('user_roles')->where('exclusive_key', 'system_super_admin')->count())->toBe(1);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'rbac.bootstrap_system_admin')->count())->toBe(1);
});

test('provisioning refuses invalid input and never promotes automatically', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    $other = Company::factory()->create(['slug' => 'other-v3']);
    [$ok] = rb_user($company, 'owner', ['email' => 'ok@example.test']);
    [$inactive] = rb_user($company, 'employee', ['email' => 'inactive@example.test', 'is_active' => false]);
    [$foreign] = rb_user($other, 'employee', ['email' => 'foreign@example.test']);
    [$platform] = rb_user($company, 'super', ['email' => 'platform@example.test']);
    $base = ['--company' => 'acme-v3', '--reason' => RB_REASON];
    foreach ([
        ['--company' => 'nope', '--email' => 'ok@example.test', '--reason' => RB_REASON],
        $base + ['--email' => 'missing@example.test'],
        $base + ['--email' => 'inactive@example.test'],
        $base + ['--email' => 'foreign@example.test'],
        $base + ['--email' => 'platform@example.test'],
        ['--company' => 'acme-v3', '--email' => 'ok@example.test', '--reason' => 'short'],
        ['--company' => 'acme-v3', '--email' => 'ok@example.test'],
    ] as $args) {
        [$code] = rb_cmd('rbac:bootstrap-system-admin', $args);
        expect($code)->toBe(1);
    }
    expect(DB::table('user_roles')->count())->toBe(0)->and(DB::table('roles')->where('key', 'system_super_admin')->count())->toBe(0);
    // registration and migration never create a protected account
    expect(PermissionResolver::for($ok))->toBe([]);
});

test('one System Super Admin per tenant: a second one is refused, transfer is atomic, reactivation never restores the old one', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$first, $firstToken] = rb_user($company, 'sysadmin', ['email' => 'first@example.test']);
    [$second, $secondToken] = rb_user($company, 'employee', ['email' => 'second@example.test']);
    [$code] = rb_cmd('rbac:bootstrap-system-admin', ['--company' => 'acme-v3', '--email' => 'second@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1)->and(DB::table('user_roles')->where('exclusive_key', 'system_super_admin')->count())->toBe(1);
    // existing session of the first holder works before the transfer
    rb_call($this, $firstToken, 'GET', '/api/v1/audit-logs')->assertOk();
    rb_call($this, $secondToken, 'GET', '/api/v1/audit-logs')->assertStatus(403);
    // dry run, then the real transfer
    [$code, $out] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'first@example.test', '--to' => 'second@example.test', '--reason' => RB_REASON, '--dry-run' => true]);
    expect($code)->toBe(0)->and($out)->toContain('Dry run');
    expect(DB::table('user_roles')->where('user_id', $first->id)->where('exclusive_key', 'system_super_admin')->exists())->toBeTrue();
    [$code, $out] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'first@example.test', '--to' => 'second@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('atomically');
    PermissionResolver::flush();
    expect(DB::table('user_roles')->where('exclusive_key', 'system_super_admin')->pluck('user_id')->all())->toBe([$second->id]);
    // the previous administrator loses access on the very next request with the EXISTING session
    rb_call($this, $firstToken, 'GET', '/api/v1/audit-logs')->assertStatus(403);
    rb_call($this, $secondToken, 'GET', '/api/v1/audit-logs')->assertOk();
    // two audit entries with the reason
    $acts = AuditLog::withoutGlobalScopes()->whereIn('action', ['rbac.transfer_system_admin.revoked', 'rbac.transfer_system_admin.granted'])->get();
    expect($acts)->toHaveCount(2)->and($acts->every(fn ($a) => $a->new_values['reason'] === RB_REASON))->toBeTrue();
    // deactivate and reactivate the replaced administrator: nothing comes back
    User::withoutGlobalScopes()->whereKey($first->id)->update(['is_active' => false]);
    User::withoutGlobalScopes()->whereKey($first->id)->update(['is_active' => true]);
    PermissionResolver::flush();
    expect(PermissionResolver::for($first->refresh()))->toBe([]);
    // repeat is idempotent; wrong previous holder is refused
    [$code, $out] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'first@example.test', '--to' => 'second@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('idempotent');
    [$code] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'first@example.test', '--to' => 'first@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1);
});

test('transfer recovers from an inactive administrator and refuses an unusable replacement', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$old] = rb_user($company, 'sysadmin', ['email' => 'old@example.test']);
    [$new] = rb_user($company, 'employee', ['email' => 'new@example.test']);
    [$gone] = rb_user($company, 'employee', ['email' => 'gone@example.test', 'is_active' => false]);
    User::withoutGlobalScopes()->whereKey($old->id)->update(['is_active' => false]);
    [$code] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'old@example.test', '--to' => 'gone@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1)->and(DB::table('user_roles')->where('user_id', $old->id)->where('exclusive_key', 'system_super_admin')->exists())->toBeTrue();
    [$code] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'old@example.test', '--to' => 'new@example.test', '--reason' => 'x']);
    expect($code)->toBe(1);
    [$code] = rb_cmd('rbac:transfer-system-admin', ['--company' => 'acme-v3', '--from' => 'old@example.test', '--to' => 'new@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0);
    PermissionResolver::flush();
    expect(PermissionResolver::for($new->refresh()))->toContain('audit_logs.view');
});

test('revoking the System Super Admin or an HR Administrator is lockout-protected, audited and idempotent', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$sa] = rb_user($company, 'sysadmin', ['email' => 'sa@example.test']);
    // the only permission administrator cannot be revoked
    [$code] = rb_cmd('rbac:revoke-system-admin', ['--company' => 'acme-v3', '--email' => 'sa@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1)->and(DB::table('user_roles')->where('user_id', $sa->id)->exists())->toBeTrue();
    [$hr] = rb_user($company, 'hr', ['email' => 'hr@example.test']);
    [$hr2] = rb_user($company, 'hr', ['email' => 'hr2@example.test']);
    // HR revocation: dry run, real, idempotent
    [$code, $out] = rb_cmd('rbac:revoke-hr', ['--company' => 'acme-v3', '--email' => 'hr2@example.test', '--reason' => RB_REASON, '--dry-run' => true]);
    expect($code)->toBe(0)->and($out)->toContain('Dry run')->and(DB::table('user_roles')->where('user_id', $hr2->id)->exists())->toBeTrue();
    [$code, $out] = rb_cmd('rbac:revoke-hr', ['--company' => 'acme-v3', '--email' => 'hr2@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('removed')->and(DB::table('user_roles')->where('user_id', $hr2->id)->exists())->toBeFalse();
    [$code, $out] = rb_cmd('rbac:revoke-hr', ['--company' => 'acme-v3', '--email' => 'hr2@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and($out)->toContain('idempotent');
    expect(AuditLog::withoutGlobalScopes()->where('action', 'rbac.revoke_hr')->count())->toBe(1);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'rbac.revoke_hr')->first()->new_values['reason'])->toBe(RB_REASON);
    // reason is mandatory
    [$code] = rb_cmd('rbac:revoke-hr', ['--company' => 'acme-v3', '--email' => 'hr@example.test', '--reason' => 'no']);
    expect($code)->toBe(1)->and(DB::table('user_roles')->where('user_id', $hr->id)->exists())->toBeTrue();
    // Phase 3.3: HR is no longer a permission administrator, so with only HR accounts left the System Super Admin
    // is still the last administrator and cannot be revoked (lockout protection follows the effective permissions).
    [$code] = rb_cmd('rbac:revoke-system-admin', ['--company' => 'acme-v3', '--email' => 'sa@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1)->and(DB::table('user_roles')->where('user_id', $sa->id)->exists())->toBeTrue();
    // once an explicitly designated custom-role administrator exists, the System Super Admin may be revoked
    [$custom] = rb_user($company, 'employee', ['email' => 'custom.admin@example.test']);
    rb_custom_role($company, ['roles.assign', 'permissions.manage'], [], $custom, 'Designated Admin');
    [$code] = rb_cmd('rbac:revoke-system-admin', ['--company' => 'acme-v3', '--email' => 'sa@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0);
    PermissionResolver::flush();
    expect(PermissionResolver::for($sa->refresh()))->toBe([]);
    // the designated administrator is now the last one: removing its role through the operator path is not possible
    // for HR accounts (they do not count), and revoking an HR account never affects lockout protection
    [$code] = rb_cmd('rbac:revoke-hr', ['--company' => 'acme-v3', '--email' => 'hr@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(0)->and(DB::table('user_roles')->where('user_id', $hr->id)->exists())->toBeFalse();
});

test('audit failure during a trusted procedure rolls the change back', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$user] = rb_user($company, 'owner', ['email' => 'dev@example.test']);
    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function log(string $action, ?Model $auditable = null, ?array $oldValues = null, ?array $newValues = null, ?User $user = null, ?string $reason = null, ?string $visibility = null): ?AuditLog
        {
            return null;
        }
    });
    [$code] = rb_cmd('rbac:bootstrap-system-admin', ['--company' => 'acme-v3', '--email' => 'dev@example.test', '--reason' => RB_REASON]);
    expect($code)->toBe(1)->and(DB::table('user_roles')->count())->toBe(0);
});

test('System Super Admin lost permission while waiting for the lock is refused (TOCTOU applies to the new role)', function () {
    $company = Company::factory()->create(['slug' => 'acme-v3']);
    [$sa] = rb_user($company, 'sysadmin', ['email' => 'sa@example.test']);
    [$hr] = rb_user($company, 'hr', ['email' => 'hr@example.test']);
    [$emp] = rb_user($company, 'employee', ['email' => 'emp@example.test']);
    $hrRole = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'hr_administrator')->firstOrFail();
    app(TenantContext::class)->set($company);
    expect(PermissionResolver::has($sa, 'roles.assign'))->toBeTrue(); // memoized before the revocation
    DB::table('user_roles')->where('user_id', $sa->id)->delete();
    expect(fn () => app(RbacService::class)->syncUserRoles($sa, $emp, [$hrRole->id]))->toThrow(RbacDenied::class);
    expect(DB::table('user_roles')->where('user_id', $emp->id)->count())->toBe(0);
    app(TenantContext::class)->clear();
});
