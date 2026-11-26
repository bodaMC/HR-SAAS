<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support.php';
uses(RefreshDatabase::class);

function lc_acct(string $e, int $id, string $op = '')
{
    return "/api/v1/employees/{$id}/account".($op ? "/$op" : '');
}

test('HR reset, deactivate and reactivate an ordinary account; reset forces a change, revokes sessions and returns a one-time password', function () {
    $w = lc_world();
    $old = $w['emp']->createToken('x')->plainTextToken;
    $res = lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertOk();
    $temp = $res->json('temporary_password');
    expect($temp)->toBeString();
    lc_call($this, $old, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, $w['empT'], 'GET', '/api/v1/auth/me')->assertStatus(401);
    expect((bool) User::withoutGlobalScopes()->find($w['emp']->id)->must_change_password)->toBeTrue();
    $t = lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => $temp])->assertOk()->json('token');
    lc_call($this, $t, 'GET', '/api/v1/employees')->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    // reason and confirmation are mandatory
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), ['confirm_employee_uuid' => $w['eEmp']->uuid])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), ['reason' => 'x', 'confirm_employee_uuid' => $w['eEmp']->uuid])->assertStatus(422);

    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertOk()->assertJsonPath('data.state', 'inactive');
    lc_call($this, $t, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => $temp])->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertStatus(409);
    // reactivation never restores sessions
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reactivate'), lc_body($w['eEmp']))->assertOk();
    expect(User::withoutGlobalScopes()->find($w['emp']->id)->tokens()->count())->toBe(0);
    lc_call($this, $t, 'GET', '/api/v1/auth/me')->assertStatus(401);
    $actions = AuditLog::withoutGlobalScopes()->pluck('action')->all();
    expect($actions)->toContain('account.password_reset', 'account.deactivated', 'account.activated');
});

test('protected accounts cannot be taken over by HR: other HR, SSA, platform admin, and custom roles with administrative authority', function () {
    $w = lc_world();
    [$plat] = lc_user($w['c'], 'super', ['email' => 'plat@a.test']);
    $ePlat = lc_emp($w['c'], ['user_id' => $plat->id, 'email' => 'w.plat@a.test']);
    [$adm, $admT] = lc_user($w['c'], 'employee', ['email' => 'adm@a.test']);
    lc_role($w['c'], ['roles.assign', 'permissions.manage', 'roles.update'], $adm);
    $eAdm = lc_emp($w['c'], ['user_id' => $adm->id, 'email' => 'w.adm@a.test']);
    foreach ([$w['eHr2'], $w['eSa'], $ePlat, $eAdm, $w['eHr']] as $target) {
        foreach (['reset-password', 'deactivate', 'unlink'] as $op) {
            lc_call($this, $w['hrT'], 'POST', lc_acct('', $target->id, $op), lc_body($target))->assertStatus(403);
        }
        lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$target->id}/terminate", lc_body($target, ['termination_date' => now()->toDateString()]))->assertStatus(403);
        lc_call($this, $w['hrT'], 'DELETE', "/api/v1/employees/{$target->id}")->assertStatus(403);
        if ($target->id === $w['eHr']->id) {
            continue;
        } // editing one's own record changes no other account
        $r = lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$target->id}", ['email' => 'hijack'.$target->id.'@a.test']);
        expect($r->status())->toBe(403, 'PUT target '.$target->email);
    }
    foreach (['hr2', 'sa'] as $k) {
        $u = User::withoutGlobalScopes()->find($w[$k]->id);
        expect((bool) $u->is_active)->toBeTrue()->and($u->tokens()->count())->toBe(1)->and((bool) $u->must_change_password)->toBeFalse();
    }
    expect(Employee::withoutGlobalScopes()->whereIn('id', [$w['eHr2']->id, $w['eSa']->id, $ePlat->id])->whereNull('user_id')->count())->toBe(0)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'account.protected_attempt')->count())->toBeGreaterThan(5)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'account.protected_attempt')->where('visibility', 'operational')->count())->toBe(0);
});

test('SSA may manage ordinary and HR accounts in its tenant but never a Platform Super Admin or itself', function () {
    $w = lc_world();
    [$plat] = lc_user($w['c'], 'super', ['email' => 'plat@a.test']);
    $ePlat = lc_emp($w['c'], ['user_id' => $plat->id, 'email' => 'w.plat@a.test']);
    lc_call($this, $w['saT'], 'POST', lc_acct('', $w['eHr2']->id, 'reset-password'), lc_body($w['eHr2']))->assertOk();
    lc_call($this, $w['saT'], 'POST', lc_acct('', $w['eHr']->id, 'deactivate'), lc_body($w['eHr']))->assertOk();
    lc_call($this, $w['saT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, $w['saT'], 'POST', lc_acct('', $ePlat->id, 'reset-password'), lc_body($ePlat))->assertStatus(403);
    lc_call($this, $w['saT'], 'POST', lc_acct('', $ePlat->id, 'deactivate'), lc_body($ePlat))->assertStatus(403);
    lc_call($this, $w['saT'], 'POST', lc_acct('', $w['eSa']->id, 'deactivate'), lc_body($w['eSa']))->assertStatus(403);
    lc_call($this, $w['saT'], 'POST', lc_acct('', $w['eSa']->id, 'reset-password'), lc_body($w['eSa']))->assertStatus(403);
});

test('HR cannot reset or change its own account through the admin path; own password changes use self-service', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eHr']->id, 'reset-password'), lc_body($w['eHr']))->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eHr']->id, 'deactivate'), lc_body($w['eHr']))->assertStatus(403);
});

test('terminate deactivates the login and revokes tokens atomically; the record stays; the employee cannot sign in; reactivation is separate', function () {
    $w = lc_world();
    $tok = $w['emp']->createToken('second')->plainTextToken;
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/terminate", ['reason' => 'Synthetic termination reason', 'confirm_employee_uuid' => $w['eEmp']->uuid, 'termination_date' => now()->toDateString()])->assertOk();
    $e = Employee::withoutGlobalScopes()->find($w['eEmp']->id);
    $u = User::withoutGlobalScopes()->find($w['emp']->id);
    expect($e->employment_status->value)->toBe('terminated')->and($e->user_id)->toBe($u->id)->and((bool) $u->is_active)->toBeFalse()->and($u->tokens()->count())->toBe(0);
    lc_call($this, $tok, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'password'])->assertStatus(403);
    // the former employee stays in the database and in the former filter
    $ids = collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/employees?scope=former&per_page=100')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toContain($w['eEmp']->id);
    expect(collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/employees')->json('data'))->pluck('id')->all())->not->toContain($w['eEmp']->id);
    expect(collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/employees?scope=all&per_page=100')->json('data'))->pluck('id')->all())->toContain($w['eEmp']->id);
    // account reactivation is refused until the employee is reactivated; reactivating the employee does not reactivate the login
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reactivate'), lc_body($w['eEmp']))->assertStatus(409)->assertJsonPath('code', 'employee_terminated');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/terminate", lc_body($w['eEmp']))->assertStatus(409);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/reactivate", lc_body($w['eEmp']))->assertOk();
    expect((bool) User::withoutGlobalScopes()->find($w['emp']->id)->is_active)->toBeFalse();
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'password'])->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reactivate'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'password'])->assertOk();
    expect(AuditLog::withoutGlobalScopes()->pluck('action')->all())->toContain('employee.terminated', 'employee.reactivated');
});

test('generic update cannot enter or leave the terminated state and cannot set user_id; user_id is not exposed', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eNone']->id}", ['employment_status' => 'terminated'])->assertStatus(422);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eNone']->id}", ['user_id' => $w['sa']->id])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'nb@a.test', 'employee_number' => 'E-NB-1', 'hire_date' => '2024-01-01', 'user_id' => $w['sa']->id])->assertStatus(422);
    $show = lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$w['eHr2']->id}")->assertOk()->json('data');
    expect($show)->not->toHaveKey('user_id')->not->toHaveKey('user')->and($show['has_account'])->toBeTrue();
    expect(Employee::withoutGlobalScopes()->find($w['eNone']->id)->user_id)->toBeNull();
});

test('link and unlink: tenant-validated, one-to-one, audited, no email auto-link', function () {
    $w = lc_world();
    [$free] = lc_user($w['c'], 'employee', ['email' => 'free@a.test']);
    $eFree = lc_emp($w['c'], ['email' => 'free@a.test']); // same email must NOT auto-link
    expect($eFree->user_id)->toBeNull();
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $eFree->id, 'link'), lc_body($eFree, ['user_id' => $free->id]))->assertOk();
    expect(Employee::withoutGlobalScopes()->find($eFree->id)->user_id)->toBe($free->id);
    // account already linked elsewhere
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eNone']->id, 'link'), lc_body($w['eNone'], ['user_id' => $free->id]))->assertStatus(409);
    // a protected account cannot be linked by HR
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eNone']->id, 'link'), lc_body($w['eNone'], ['user_id' => $w['sa']->id]))->assertStatus(403);
    // cross-tenant account
    $b = lc_world('b.test');
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eNone']->id, 'link'), lc_body($w['eNone'], ['user_id' => $b['emp']->id]))->assertStatus(404);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $eFree->id, 'unlink'), lc_body($eFree))->assertOk();
    expect(Employee::withoutGlobalScopes()->find($eFree->id)->user_id)->toBeNull()
        ->and(User::withoutGlobalScopes()->find($free->id)->tokens()->count())->toBe(0);
    expect(AuditLog::withoutGlobalScopes()->pluck('action')->all())->toContain('account.linked', 'account.unlinked');
});

test('cross-tenant isolation: HR of one company cannot touch another company', function () {
    $a = lc_world('a.test');
    $b = lc_world('b.test');
    foreach (['reset-password', 'deactivate', 'reactivate', 'unlink'] as $op) {
        lc_call($this, $a['hrT'], 'POST', lc_acct('', $b['eEmp']->id, $op), lc_body($b['eEmp']))->assertStatus(404);
    }
    lc_call($this, $a['hrT'], 'POST', "/api/v1/employees/{$b['eEmp']->id}/terminate", lc_body($b['eEmp']))->assertStatus(404);
    expect((bool) User::withoutGlobalScopes()->find($b['emp']->id)->is_active)->toBeTrue();
});

test('only users with the matching accounts.* permission may act; employees cannot', function () {
    $w = lc_world();
    foreach (['reset-password', 'deactivate', 'reactivate', 'unlink', 'link'] as $op) {
        lc_call($this, $w['empT'], 'POST', lc_acct('', $w['eNone']->id, $op), lc_body($w['eNone'], ['user_id' => $w['emp']->id]))->assertStatus(403);
    }
    [$only, $onlyT] = lc_user($w['c'], 'employee', ['email' => 'only@a.test']);
    lc_role($w['c'], ['accounts.view', 'accounts.reset_password', 'employees.view'], $only);
    lc_call($this, $onlyT, 'POST', lc_acct('', $w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertStatus(403);
    lc_call($this, $onlyT, 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertOk();
});

test('privilege elevation of the target is revalidated: a target who gained administrative authority is protected', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reactivate'), lc_body($w['eEmp']))->assertOk();
    lc_role($w['c'], ['roles.assign', 'permissions.manage'], $w['emp']);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertStatus(403);
});

test('a deactivated or revoked HR actor loses lifecycle authority immediately (fresh authorization)', function () {
    $w = lc_world();
    DB::table('user_roles')->where('user_id', $w['hr']->id)->delete();
    lc_call($this, $w['hrT'], 'POST', lc_acct('', $w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertStatus(403);
});

test('trainee flag and employment type: independent, nullable, filterable, validated, admin-editable', function () {
    $w = lc_world();
    expect(Employee::withoutGlobalScopes()->find($w['eNone']->id)->employment_type)->toBeNull();
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eNone']->id}", ['employment_type' => 'part_time', 'is_trainee' => true])->assertOk()->assertJsonPath('data.employment_type', 'part_time')->assertJsonPath('data.is_trainee', true);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eNone']->id}", ['employment_type' => 'freelance'])->assertStatus(422);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eEmp']->id}", ['employment_type' => 'full_time'])->assertOk();
    $ids = fn ($q) => collect(lc_call($this, $w['hrT'], 'GET', "/api/v1/employees?per_page=100&$q")->assertOk()->json('data'))->pluck('id')->all();
    expect($ids('is_trainee=1'))->toBe([$w['eNone']->id])
        ->and($ids('employment_type=part_time'))->toBe([$w['eNone']->id])
        ->and($ids('employment_type=full_time'))->toBe([$w['eEmp']->id])
        ->and($ids('employment_type=none'))->toContain($w['eHr']->id)->not->toContain($w['eEmp']->id)
        ->and($ids('is_trainee=0'))->not->toContain($w['eNone']->id);
    // employees themselves cannot edit either through the profile endpoint
    lc_call($this, $w['empT'], 'PUT', '/api/v1/me/profile', ['is_trainee' => true])->assertStatus(422);
    lc_call($this, $w['empT'], 'PUT', '/api/v1/me/profile', ['employment_type' => 'full_time'])->assertStatus(422);
});

test('directory defaults to current employees', function () {
    $w = lc_world();
    $term = lc_emp($w['c'], ['employment_status' => 'terminated', 'email' => 'tt@a.test']);
    $def = collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/employees')->json('data'))->pluck('id')->all();
    expect($def)->not->toContain($term->id)->toContain($w['eNone']->id);
});
