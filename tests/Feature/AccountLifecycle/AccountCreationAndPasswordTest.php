<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/Support.php';
uses(RefreshDatabase::class);

test('HR creates a login for an employee: generated one-time temporary password, forced change, no roles, atomic link', function () {
    $w = lc_world();
    $res = lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/account", [
        'email' => 'New.Person@A.test', 'confirm_employee_uuid' => $w['eNone']->uuid,
        // hostile extras are ignored
        'company_id' => 999, 'is_super_admin' => true, 'role' => 'owner', 'roles' => [1],
    ])->assertCreated();
    $temp = $res->json('temporary_password');
    expect($temp)->toBeString()->and(strlen($temp))->toBe(16)
        ->and($res->json('data.state'))->toBe('password_change_required')
        ->and($res->json('data.login_email'))->toBe('new.person@a.test');
    $user = User::withoutGlobalScopes()->where('email', 'new.person@a.test')->firstOrFail();
    expect($user->company_id)->toBe($w['c']->id)->and($user->is_super_admin)->toBeFalse()
        ->and($user->role->value)->toBe('employee')->and((bool) $user->must_change_password)->toBeTrue()
        ->and(Hash::check($temp, $user->password))->toBeTrue()->and($user->password)->not->toBe($temp)
        ->and(DB::table('user_roles')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Employee::withoutGlobalScopes()->find($w['eNone']->id)->user_id)->toBe($user->id);
    // the temporary password is never shown again and never stored in the audit trail
    $again = lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$w['eNone']->id}/account")->assertOk();
    expect(json_encode($again->json()))->not->toContain($temp);
    $logs = AuditLog::withoutGlobalScopes()->where('company_id', $w['c']->id)->get();
    expect(json_encode($logs->toArray()))->not->toContain($temp)->not->toContain('password":"')
        ->and($logs->where('action', 'account.created')->first()->user_id)->toBe($w['hr']->id)
        ->and($logs->where('action', 'account.created')->first()->visibility)->toBe('operational');
    // same employee again, and another account for a linked employee: refused
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/account", ['email' => 'x@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(409)->assertJsonPath('code', 'already_linked');
});

test('account creation validates confirmation, global email uniqueness (soft-deleted included), password policy and terminated employees', function () {
    $w = lc_world();
    $url = "/api/v1/employees/{$w['eNone']->id}/account";
    lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'x@a.test', 'confirm_employee_uuid' => $w['eHr']->uuid])->assertStatus(422)->assertJsonValidationErrors('confirm_employee_uuid');
    lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'x@a.test'])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'EMP@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(422)->assertJsonValidationErrors('email');
    // soft-deleted accounts keep their email reserved (database-level global uniqueness)
    [$gone] = lc_user($w['c'], 'employee', ['email' => 'gone@a.test']);
    $gone->delete();
    lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'gone@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(422)->assertJsonValidationErrors('email');
    // another tenant's email is also taken
    $other = lc_world('o.test');
    lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'sa@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(422);
    foreach (['short1A', 'alllowercase123', 'ALLUPPERCASE123', 'NoNumbersHereAtAll'] as $weak) {
        lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'p@a.test', 'password' => $weak, 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(422)->assertJsonValidationErrors('password');
    }
    $res = lc_call($this, $w['hrT'], 'POST', $url, ['email' => 'p@a.test', 'password' => LC_PW, 'status' => 'inactive', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertCreated();
    expect($res->json('temporary_password'))->toBeNull()->and($res->json('data.state'))->toBe('inactive');
    $term = lc_emp($w['c'], ['employment_status' => 'terminated', 'email' => 't@a.test']);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$term->id}/account", ['email' => 't2@a.test', 'confirm_employee_uuid' => $term->uuid])->assertStatus(409)->assertJsonPath('code', 'employee_terminated');
    expect($other['c']->id)->not->toBe($w['c']->id);
});

test('account creation requires accounts.create; employees and custom roles without it are refused', function () {
    $w = lc_world();
    $url = "/api/v1/employees/{$w['eNone']->id}/account";
    lc_call($this, $w['empT'], 'POST', $url, ['email' => 'x@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(403);
    [$clerk, $clerkT] = lc_user($w['c'], 'employee', ['email' => 'clerk@a.test']);
    lc_role($w['c'], ['employees.view', 'accounts.view'], $clerk);
    lc_call($this, $clerkT, 'GET', $url)->assertOk();
    lc_call($this, $clerkT, 'POST', $url, ['email' => 'x@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(403);
    lc_call($this, $w['empT'], 'GET', $url)->assertStatus(403);
    // cross tenant employee: not found
    $b = lc_world('b.test');
    lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$b['eNone']->id}/account")->assertStatus(404);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$b['eNone']->id}/account", ['email' => 'zz@a.test', 'confirm_employee_uuid' => $b['eNone']->uuid])->assertStatus(404);
});

test('account creation is atomic: an audit failure rolls back the user and the link', function () {
    $w = lc_world();
    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function log(string $action, ?Model $auditable = null, ?array $oldValues = null, ?array $newValues = null, ?User $user = null, ?string $reason = null, ?string $visibility = null): ?AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/account", ['email' => 'atomic@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->assertStatus(500);
    expect(User::withoutGlobalScopes()->where('email', 'atomic@a.test')->exists())->toBeFalse()
        ->and(Employee::withoutGlobalScopes()->find($w['eNone']->id)->user_id)->toBeNull();
});

test('first login: the temporary password works once, every API except password change, identity and logout is blocked server-side', function () {
    $w = lc_world();
    $temp = lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eNone']->id}/account", ['email' => 'first@a.test', 'confirm_employee_uuid' => $w['eNone']->uuid])->json('temporary_password');
    $login = lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'first@a.test', 'password' => $temp])->assertOk();
    $t = $login->json('token');
    expect($login->json('user.must_change_password'))->toBeTrue();
    foreach (['/api/v1/employees', '/api/v1/departments', '/api/v1/me/profile', '/api/v1/work-schedule', '/api/v1/holidays', '/api/v1/notifications', '/api/v1/leave-requests', '/api/v1/access/roles', '/api/v1/audit-logs'] as $uri) {
        lc_call($this, $t, 'GET', $uri)->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }
    lc_call($this, $t, 'PUT', '/api/v1/me/profile', ['phone' => '123'])->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    lc_call($this, $t, 'POST', '/api/v1/me/profile/picture')->assertStatus(403)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    lc_call($this, $t, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);

    // validation: wrong current, same as current, weak, mismatch
    $u = '/api/v1/auth/change-password';
    lc_call($this, $t, 'POST', $u, ['current_password' => 'wrong', 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertStatus(422)->assertJsonValidationErrors('current_password');
    lc_call($this, $t, 'POST', $u, ['current_password' => $temp, 'password' => $temp, 'password_confirmation' => $temp])->assertStatus(422)->assertJsonValidationErrors('password');
    lc_call($this, $t, 'POST', $u, ['current_password' => $temp, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(422)->assertJsonValidationErrors('password');
    lc_call($this, $t, 'POST', $u, ['current_password' => $temp, 'password' => LC_PW, 'password_confirmation' => LC_PW.'x'])->assertStatus(422)->assertJsonValidationErrors('password_confirmation');
    lc_call($this, $t, 'GET', '/api/v1/employees')->assertStatus(403);

    $ok = lc_call($this, $t, 'POST', $u, ['current_password' => $temp, 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertOk();
    $new = $ok->json('token');
    expect($new)->toBeString()->and($new)->not->toBe($t);
    // the old token (the one used for the change) is revoked; the new session works; the flag is cleared
    lc_call($this, $t, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, $new, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('user.must_change_password', false);
    lc_call($this, $new, 'GET', '/api/v1/me/profile')->assertOk();
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'first@a.test', 'password' => $temp])->assertStatus(422);
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'first@a.test', 'password' => LC_PW])->assertOk()->assertJsonPath('user.must_change_password', false);
    expect(json_encode(AuditLog::withoutGlobalScopes()->get()->toArray()))->not->toContain(LC_PW)->not->toContain($temp);
});

test('self-service change password revokes every other session and works for ordinary accounts', function () {
    $w = lc_world();
    $second = $w['emp']->createToken('other-device')->plainTextToken;
    lc_call($this, $w['empT'], 'POST', '/api/v1/auth/change-password', ['current_password' => 'password', 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertOk();
    lc_call($this, $second, 'GET', '/api/v1/auth/me')->assertStatus(401);
    lc_call($this, $w['empT'], 'GET', '/api/v1/auth/me')->assertStatus(401);
    expect($w['emp']->tokens()->count())->toBe(1);
});

test('existing accounts are not forced to change their password', function () {
    $w = lc_world();
    foreach (['sa', 'hr', 'hr2', 'emp'] as $k) {
        expect((bool) User::withoutGlobalScopes()->find($w[$k]->id)->must_change_password)->toBeFalse();
    }
    lc_call($this, $w['empT'], 'GET', '/api/v1/auth/me')->assertOk();
});

test('login is rate limited per email and IP after repeated failures; successful sign-in is unaffected before the limit', function () {
    $w = lc_world();
    for ($i = 0; $i < 5; $i++) {
        lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'bad'.$i])->assertStatus(422);
    }
    $r = lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'emp@a.test', 'password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    expect($r->headers->get('Retry-After'))->not->toBeNull();
    // another account from the same IP is still served
    lc_call($this, null, 'POST', '/api/v1/auth/login', ['email' => 'hr1@a.test', 'password' => 'password'])->assertOk();
    expect($w['c']->id)->toBeInt();
});

test('password endpoints are rate limited', function () {
    $w = lc_world();
    for ($i = 0; $i < 10; $i++) {
        lc_call($this, $w['empT'], 'POST', '/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertStatus(422);
    }
    lc_call($this, $w['empT'], 'POST', '/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => LC_PW, 'password_confirmation' => LC_PW])->assertStatus(429);
});
