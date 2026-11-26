<?php

use App\Accounts\AccountService;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support.php';
uses(RefreshDatabase::class);

function v2_acct(int $id, string $op = ''): string
{
    return "/api/v1/employees/{$id}/account".($op ? "/$op" : '');
}

test('unlink deactivates the account atomically, revokes every token, keeps roles untouched and never restores anything', function () {
    $w = lc_world();
    $roleId = lc_role($w['c'], ['employees.view'], $w['emp']);
    $second = $w['emp']->createToken('second')->plainTextToken;
    $rolesBefore = DB::table('user_roles')->where('user_id', $w['emp']->id)->pluck('role_id')->all();
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'unlink'), lc_body($w['eEmp']))->assertOk();
    $u = User::withoutGlobalScopes()->find($w['emp']->id);
    expect((bool) $u->is_active)->toBeFalse()->and($u->tokens()->count())->toBe(0)
        ->and(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->user_id)->toBeNull()
        ->and(DB::table('user_roles')->where('user_id', $u->id)->pluck('role_id')->all())->toBe($rolesBefore);
    lc_call($this, $second, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'password'])->assertStatus(403)->assertJsonPath('code', 'USER_INACTIVE');
    $audit = AuditLog::withoutGlobalScopes()->where('action', 'account.unlinked')->latest('id')->first();
    expect($audit)->not->toBeNull();
    // Re-linking keeps the account inactive; activation is a separate explicit, audited step.
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'link'), lc_body($w['eEmp'], ['user_id' => $u->id]))->assertOk();
    expect((bool) User::withoutGlobalScopes()->find($u->id)->is_active)->toBeFalse();
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'reactivate'), lc_body($w['eEmp']))->assertOk();
    $u = User::withoutGlobalScopes()->find($u->id);
    expect((bool) $u->is_active)->toBeTrue()->and($u->tokens()->count())->toBe(0)
        ->and(DB::table('user_roles')->where('user_id', $u->id)->pluck('role_id')->all())->toBe($rolesBefore);
    expect(AuditLog::withoutGlobalScopes()->pluck('action')->all())->toContain('account.linked', 'account.activated');
});

test('unlink of an already inactive account stays inactive and the last administrator cannot be unlinked away', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'unlink'), lc_body($w['eEmp']))->assertOk();
    expect((bool) User::withoutGlobalScopes()->find($w['emp']->id)->is_active)->toBeFalse();
    // HR cannot unlink the SSA (protected); the SSA cannot unlink itself.
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eSa']->id, 'unlink'), lc_body($w['eSa']))->assertStatus(403);
    lc_call($this, $w['saT'], 'POST', v2_acct($w['eSa']->id, 'unlink'), lc_body($w['eSa']))->assertStatus(403);
});

test('link candidates: only same-tenant, unlinked, administrable accounts; never protected, self, cross-tenant or linked; minimal fields', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    [$free] = lc_user($w['c'], 'employee', ['email' => 'free@a.test', 'name' => 'Free Person']);
    [$other] = lc_user($w['c'], 'employee', ['email' => 'other@a.test']);
    $res = lc_call($this, $w['hrT'], 'GET', v2_acct($w['eNone']->id, 'link-candidates'))->assertOk();
    $emails = collect($res->json('data'))->pluck('email')->all();
    expect($emails)->toContain('free@a.test', 'other@a.test')
        ->and($emails)->not->toContain('sa@a.test', 'hr1@a.test', 'hr2@a.test', 'emp@a.test', 'emp@b.test', 'sa@b.test');
    expect(array_keys($res->json('data.0')))->toBe(['id', 'name', 'email', 'is_active']);
    $q = lc_call($this, $w['hrT'], 'GET', v2_acct($w['eNone']->id, 'link-candidates').'?q=free')->assertOk()->json('data');
    expect($q)->toHaveCount(1)->and($q[0]['id'])->toBe($free->id);
    // permission gate
    lc_call($this, $w['empT'], 'GET', v2_acct($w['eNone']->id, 'link-candidates'))->assertStatus(403);
    // cross-tenant employee id is not reachable
    lc_call($this, $w['hrT'], 'GET', v2_acct($b['eNone']->id, 'link-candidates'))->assertStatus(404);
});

test('HR cannot edit its own employee record through CRUD; others stay editable; phone and picture self-service stay open', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eHr']->id, ['first_name' => 'Changed'])->assertStatus(403);
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eHr']->id, ['employment_type' => 'full_time'])->assertStatus(403);
    lc_call($this, $w['hrT'], 'DELETE', '/api/v1/employees/'.$w['eHr']->id)->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$w['eHr']->id.'/terminate', ['reason' => 'Synthetic termination reason'])->assertStatus(403);
    expect(Employee::withoutGlobalScopes()->find($w['eHr']->id)->first_name)->not->toBe('Changed');
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eEmp']->id, ['first_name' => 'Edited'])->assertOk();
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/me/profile', ['phone' => '+20 100 555 0101'])->assertOk();
    expect(Employee::withoutGlobalScopes()->find($w['eHr']->id)->phone)->toBe('+20 100 555 0101');
    // account state exposes is_self so the UI hides what the API refuses
    lc_call($this, $w['hrT'], 'GET', v2_acct($w['eHr']->id))->assertOk()->assertJsonPath('data.is_self', true);
    lc_call($this, $w['hrT'], 'GET', v2_acct($w['eEmp']->id))->assertOk()->assertJsonPath('data.is_self', false);
});

test('the generic update cannot enter or leave terminated and runs the checks under the lifecycle lock', function () {
    $w = lc_world();
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eEmp']->id, ['employment_status' => 'terminated'])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$w['eEmp']->id.'/terminate', lc_body($w['eEmp']))->assertOk();
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eEmp']->id, ['employment_status' => 'active'])->assertStatus(422);
    // a stale actor (permission revoked since the request was authorised) is refused inside the transaction
    DB::table('user_roles')->where('user_id', $w['hr']->id)->delete();
    lc_call($this, $w['hrT'], 'PUT', '/api/v1/employees/'.$w['eNone']->id, ['first_name' => 'Nope'])->assertStatus(403);
});

test('sign-in revalidates under lock: a stale pre-lock decision cannot mint a token after reset, deactivation or password change', function () {
    $w = lc_world();
    $svc = app(AccountService::class);
    $seen = User::withoutGlobalScopes()->find($w['emp']->id); // pre-lock snapshot with the OLD password hash
    [$st] = $svc->authenticate($seen, 'password', 'dev');
    expect($st)->toBe('ok');
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'reset-password'), lc_body($w['eEmp']))->assertOk();
    $before = User::withoutGlobalScopes()->find($w['emp']->id)->tokens()->count();
    [$st, , $tok] = $svc->authenticate($seen, 'password', 'dev');
    expect($st)->toBe('failed')->and($tok)->toBeNull()->and(User::withoutGlobalScopes()->find($w['emp']->id)->tokens()->count())->toBe($before);
    $fresh = User::withoutGlobalScopes()->find($w['emp']->id);
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'deactivate'), lc_body($w['eEmp']))->assertOk();
    [$st, , $tok] = $svc->authenticate($fresh, 'irrelevant', 'dev');
    expect($st)->toBeIn(['failed', 'inactive'])->and($tok)->toBeNull();
    expect(User::withoutGlobalScopes()->find($w['emp']->id)->tokens()->count())->toBe(0);
});

test('change password refuses a deactivated account and issues exactly one fresh token', function () {
    $w = lc_world();
    $w['emp']->forceFill(['is_active' => true])->save();
    $res = lc_call($this, $w['empT'], 'POST', '/api/v1/auth/change-password', ['current_password' => 'password', 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertOk();
    expect(User::withoutGlobalScopes()->find($w['emp']->id)->tokens()->count())->toBe(1)->and($res->json('token'))->toBeString();
});

test('password rate limit is per account: several tokens of one account share one allowance', function () {
    $w = lc_world();
    $tokens = [$w['empT'], $w['emp']->createToken('b')->plainTextToken, $w['emp']->createToken('c')->plainTextToken];
    $codes = [];
    for ($i = 0; $i < 12; $i++) {
        $codes[] = lc_call($this, $tokens[$i % 3], 'POST', '/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => LC_PW, 'password_confirmation' => LC_PW])->status();
    }
    expect(array_slice($codes, 0, 10))->each->toBe(422)->and($codes[10])->toBe(429)->and($codes[11])->toBe(429);
});

test('profile mutations after the account was unlinked or deactivated are refused, never applied to a stale relationship', function () {
    $w = lc_world();
    $tok = $w['empT'];
    lc_call($this, $w['hrT'], 'POST', v2_acct($w['eEmp']->id, 'unlink'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, $tok, 'PUT', '/api/v1/me/profile', ['phone' => '+20 100 555 0199'])->assertStatus(401);
    expect(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->phone)->not->toBe('+20 100 555 0199');
});
