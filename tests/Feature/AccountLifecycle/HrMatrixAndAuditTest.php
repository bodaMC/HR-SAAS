<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Services\Audit\AuditVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/Support.php';
uses(RefreshDatabase::class);

test('HR has operational and account permissions but no RBAC permissions and no financial permissions', function () {
    $w = lc_world();
    PermissionResolver::flush();
    $p = PermissionResolver::for(User::withoutGlobalScopes()->find($w['hr']->id));
    foreach (['employees.view', 'employees.create', 'departments.update', 'accounts.view', 'accounts.create', 'accounts.reset_password', 'accounts.link', 'accounts.activate', 'accounts.deactivate', 'work_schedules.manage', 'company_settings.manage', 'approval_workflows.manage', 'audit_logs.view_operational'] as $perm) {
        expect($p)->toContain($perm);
    }
    foreach (['roles.view', 'roles.update', 'roles.assign', 'permissions.view', 'permissions.manage', 'audit_logs.view'] as $perm) {
        expect($p)->not->toContain($perm);
    }
    expect(array_filter($p, fn ($x) => str_starts_with($x, 'payroll') || str_starts_with($x, 'finance')))->toBe([]);
});

test('HR is denied every Roles & Permissions API server-side; SSA is allowed', function () {
    $w = lc_world();
    foreach (['/api/v1/access/roles', '/api/v1/access/permissions', '/api/v1/access/users', "/api/v1/access/users/{$w['emp']->id}/permissions", "/api/v1/access/users/{$w['hr2']->id}/permissions", "/api/v1/access/users/{$w['sa']->id}/permissions"] as $u) {
        lc_call($this, $w['hrT'], 'GET', $u)->assertStatus(403);
    }
    lc_call($this, $w['hrT'], 'POST', '/api/v1/access/roles', ['name' => 'X', 'permissions' => ['employees.view']])->assertStatus(403);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/access/users/{$w['emp']->id}/roles", ['role_ids' => []])->assertStatus(403);
    lc_call($this, $w['saT'], 'GET', '/api/v1/access/roles')->assertOk();
    lc_call($this, $w['saT'], 'GET', "/api/v1/access/users/{$w['emp']->id}/permissions")->assertOk();
    // /auth/me still exposes the caller's own effective permissions (so the UI hides RBAC for HR)
    $me = lc_call($this, $w['hrT'], 'GET', '/api/v1/auth/me')->assertOk();
    expect(json_encode($me->json()))->not->toContain('roles.update');
});

test('only an SSA may assign or revoke the HR role; HR cannot delegate or take it', function () {
    $w = lc_world();
    $hrRole = DB::table('roles')->where('company_id', $w['c']->id)->where('key', 'hr_admin')->value('id') ?? DB::table('roles')->where('company_id', $w['c']->id)->where('name', 'like', 'HR%')->value('id');
    expect($hrRole)->not->toBeNull();
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/access/users/{$w['emp']->id}/roles", ['role_ids' => [$hrRole]])->assertStatus(403);
    expect(DB::table('user_roles')->where('user_id', $w['emp']->id)->count())->toBe(0);
    lc_call($this, $w['saT'], 'PUT', "/api/v1/access/users/{$w['emp']->id}/roles", ['role_ids' => [$hrRole]])->assertOk();
    expect(DB::table('user_roles')->where('user_id', $w['emp']->id)->count())->toBe(1);
});

test('audit logs: HR sees operational events only; restricted events and their counts/metadata never leak; SSA sees all', function () {
    $w = lc_world();
    $log = fn (string $a, array $meta = []) => DB::table('audit_logs')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'user_id' => $w['sa']->id, 'action' => $a, 'auditable_type' => 'x', 'auditable_id' => 1,
        'new_values' => json_encode($meta), 'visibility' => AuditVisibility::for($a), 'created_at' => now(),
    ]);
    $op = $log('employee.updated', ['k' => 'visible']);
    $rs = $log('rbac.role_created', ['secret' => 'RESTRICTED-META']);
    $pa = $log('account.protected_attempt', ['secret' => 'RESTRICTED-META']);
    $unk = $log('some.unknown_action', ['secret' => 'RESTRICTED-META']);

    $hr = lc_call($this, $w['hrT'], 'GET', '/api/v1/audit-logs')->assertOk();
    $ids = collect($hr->json('data'))->pluck('id')->all();
    expect($ids)->toContain($op)->not->toContain($rs)->not->toContain($pa)->not->toContain($unk);
    expect(json_encode($hr->json()))->not->toContain('RESTRICTED-META');
    // filters, search and pagination cannot reach restricted rows or reveal their counts
    $f = lc_call($this, $w['hrT'], 'GET', '/api/v1/audit-logs?action=rbac.role_created')->assertOk();
    expect($f->json('data'))->toBe([])->and($f->json('meta.total'))->toBe(0);
    $f = lc_call($this, $w['hrT'], 'GET', '/api/v1/audit-logs')->assertOk();
    $totalHr = AuditLog::withoutGlobalScopes()->where('company_id', $w['c']->id)->where('visibility', 'operational')->count();
    expect($f->json('meta.total'))->toBe($totalHr);
    lc_call($this, $w['hrT'], 'GET', "/api/v1/audit-logs/$op")->assertOk();
    foreach ([$rs, $pa, $unk] as $id) {
        lc_call($this, $w['hrT'], 'GET', "/api/v1/audit-logs/$id")->assertStatus(404);
    }
    $sa = lc_call($this, $w['saT'], 'GET', '/api/v1/audit-logs')->assertOk();
    expect(collect($sa->json('data'))->pluck('id')->all())->toContain($op, $rs, $pa, $unk);
    lc_call($this, $w['saT'], 'GET', "/api/v1/audit-logs/$rs")->assertOk();
    lc_call($this, $w['empT'], 'GET', '/api/v1/audit-logs')->assertStatus(403);
    // another tenant's logs are never visible
    $b = lc_world('b.test');
    $other = DB::table('audit_logs')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $b['c']->id, 'user_id' => $b['sa']->id, 'action' => 'employee.updated', 'auditable_type' => 'x', 'auditable_id' => 1, 'visibility' => 'operational', 'created_at' => now()]);
    lc_call($this, $w['hrT'], 'GET', "/api/v1/audit-logs/$other")->assertStatus(404);
    lc_call($this, $w['saT'], 'GET', "/api/v1/audit-logs/$other")->assertStatus(404);
});

test('lifecycle audit rows carry the right visibility: HR-visible for ordinary targets, restricted for protected targets', function () {
    $w = lc_world();
    lc_call($this, $w['saT'], 'POST', "/api/v1/employees/{$w['eHr2']->id}/account/reset-password", lc_body($w['eHr2']))->assertOk();
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/account/reset-password", lc_body($w['eEmp']))->assertOk();
    $rows = AuditLog::withoutGlobalScopes()->where('action', 'account.password_reset')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->visibility)->toBe('restricted')->and($rows[1]->visibility)->toBe('operational')->and($rows[1]->reason)->toContain('Synthetic');
});

test('rbac:reconcile-roles: dry-run changes nothing; real run fixes HR, is idempotent, preserves assignments and SSA, reports custom roles', function () {
    $w = lc_world();
    $hrRoleId = DB::table('user_roles')->where('user_id', $w['hr']->id)->value('role_id');
    // simulate a pre-3.3 HR role: RBAC permissions present, accounts.* absent
    DB::table('role_permissions')->where('role_id', $hrRoleId)->where('permission', 'like', 'accounts.%')->delete();
    DB::table('role_permissions')->insert([['role_id' => $hrRoleId, 'permission' => 'roles.update', 'created_at' => now()], ['role_id' => $hrRoleId, 'permission' => 'permissions.manage', 'created_at' => now()]]);
    lc_role($w['c'], ['roles.update', 'employees.view'], $w['emp'], 'Legacy custom');
    $assignments = DB::table('user_roles')->count();
    $perms = fn () => DB::table('role_permissions')->where('role_id', $hrRoleId)->pluck('permission')->sort()->values()->all();
    $before = $perms();

    Artisan::call('rbac:reconcile-roles', ['--dry-run' => true, '--json' => true]);
    $dry = json_decode(Artisan::output(), true);
    expect($perms())->toBe($before)->and(AuditLog::withoutGlobalScopes()->where('action', 'like', 'rbac.roles_reconciled')->count())->toBe(0)->and($dry)->toBeArray();

    expect(Artisan::call('rbac:reconcile-roles', ['--json' => true]))->toBe(0);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'rbac.roles_reconciled')->count())->toBeGreaterThan(0);
    $after = $perms();
    expect($after)->toContain('accounts.create', 'accounts.reset_password', 'audit_logs.view_operational', 'approval_workflows.manage')->not->toContain('roles.update', 'permissions.manage');
    expect(DB::table('user_roles')->count())->toBe($assignments);
    // SSA untouched
    PermissionResolver::flush();
    expect(PermissionResolver::for(User::withoutGlobalScopes()->find($w['sa']->id)))->toContain('roles.update', 'audit_logs.view');
    // idempotent
    $out1 = $after;
    Artisan::call('rbac:reconcile-roles', ['--json' => true]);
    expect($perms())->toBe($out1);
    $json = Artisan::output();
    expect($json)->toContain('Legacy custom');
    // custom role was reported, not modified
    expect(DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->where('name', 'like', 'Legacy custom%')->pluck('id'))->pluck('permission')->all())->toContain('roles.update');
    // no elevation of unrelated accounts
    expect(DB::table('user_roles')->where('user_id', $w['eNone']->user_id ?? 0)->count())->toBe(0)->and(DB::table('user_roles')->where('user_id', $w['emp']->id)->count())->toBe(1);
});

test('a legacy Owner/Admin account regains nothing through reconcile', function () {
    $c = Company::factory()->create();
    [$own] = lc_user($c, 'owner', ['email' => 'own@x.test']);
    [$adm] = lc_user($c, 'admin', ['email' => 'adm@x.test']);
    lc_user($c, 'sysadmin', ['email' => 'ssa@x.test']);
    Artisan::call('rbac:reconcile-roles', []);
    expect(DB::table('user_roles')->whereIn('user_id', [$own->id, $adm->id])->count())->toBe(0);
    PermissionResolver::flush();
    expect(PermissionResolver::for($own->fresh()))->toBe([])->and(PermissionResolver::for($adm->fresh()))->toBe([]);
});

test('lockout protection: the last permission administrator cannot be revoked; HR does not count as one', function () {
    $w = lc_world();
    [$code] = [Artisan::call('rbac:revoke-system-admin', ['--company' => (string) $w['c']->id, '--email' => 'sa@a.test', '--reason' => 'Synthetic revoke attempt for lockout test'])];
    expect($code)->not->toBe(0)->and(DB::table('user_roles')->where('user_id', $w['sa']->id)->count())->toBe(1);
});

test('accounts:preflight is read-only and reports inconsistent data', function () {
    $w = lc_world();
    $before = [DB::table('users')->count(), DB::table('employees')->count(), DB::table('audit_logs')->count()];
    $code = Artisan::call('accounts:preflight', ['--json' => true]);
    $out = Artisan::output();
    expect($out)->toBeJson()->and([DB::table('users')->count(), DB::table('employees')->count(), DB::table('audit_logs')->count()])->toBe($before)->and($code)->toBeIn([0, 1]);
});

test('tenant:provision creates a company, one SSA with a forced-change temporary password, and refuses duplicates', function () {
    expect(Artisan::call('tenant:provision', ['--name' => 'Synthetic Co', '--slug' => 'synthetic-co', '--admin-name' => 'Op Admin', '--admin-email' => 'op@synthetic.test', '--reason' => 'Synthetic provisioning for test run']))->toBe(0);
    $out = Artisan::output();
    $u = User::withoutGlobalScopes()->where('email', 'op@synthetic.test')->firstOrFail();
    expect((bool) $u->must_change_password)->toBeTrue()->and(DB::table('user_roles')->where('user_id', $u->id)->count())->toBe(1);
    preg_match('/[A-Za-z0-9!@#$%^&*_\-]{16}/', $out, $m);
    expect($out)->toContain('op@synthetic.test');
    expect(AuditLog::withoutGlobalScopes()->where('company_id', $u->company_id)->where('visibility', 'restricted')->count())->toBeGreaterThan(0);
    expect(Artisan::call('tenant:provision', ['--name' => 'Synthetic Co 2', '--slug' => 'synthetic-co', '--admin-name' => 'X', '--admin-email' => 'op@synthetic.test', '--reason' => 'Synthetic duplicate attempt']))->not->toBe(0);
    // public registration endpoint is gone
    lc_call($this, null, 'POST', '/api/v1/auth/register-company', ['company_name' => 'x'])->assertStatus(404);
});

test('a caller without account permissions gets 403 on every account mutation even with an empty or invalid body (authorization precedes validation)', function () {
    $w = lc_world();
    foreach (['', '/link', '/unlink', '/reset-password', '/deactivate', '/reactivate'] as $suffix) {
        lc_call($this, $w['empT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/account$suffix", [])->assertStatus(403);
    }
    lc_call($this, $w['empT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/terminate", [])->assertStatus(403);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'like', 'account.%')->count())->toBe(0);
});
