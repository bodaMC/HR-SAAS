<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dir_seed(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->hr()->create(['role' => UserRole::Owner]);
    $token = $user->createToken('t')->plainTextToken;
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $fin = Department::factory()->create(['name' => 'Finance']);
    $dev = JobTitle::factory()->create(['name' => 'Developer']);
    $acc = JobTitle::factory()->create(['name' => 'Accountant']);
    $mk = fn ($n, $f, $l, $d, $j, $s = 'active', $m = null) => Employee::factory()->create([
        'employee_number' => $n, 'first_name' => $f, 'last_name' => $l, 'email' => strtolower($n).'@example.test',
        'department_id' => $d->id, 'job_title_id' => $j->id, 'employment_status' => $s, 'manager_id' => $m,
    ]);
    $e1 = $mk('E1', 'Ahmed', 'Hassan', $eng, $dev);
    $e2 = $mk('E2', 'أحمد', 'سالم', $eng, $dev, 'on_leave');
    $e3 = $mk('E3', 'Sara', 'Ali', $fin, $acc, 'active', $e1->id);
    $e4 = $mk('E4', 'Mona', 'Adel', $fin, $acc, 'terminated');
    $e5 = $mk('E5', 'محمد', 'علي', $fin, $dev, 'active', $e1->id);
    app(TenantContext::class)->clear();

    return compact('company', 'token', 'eng', 'fin', 'dev', 'acc', 'e1', 'e2', 'e3', 'e4', 'e5');
}

function dir_get($t, string $token, string $qs = '')
{
    app('auth')->forgetGuards();

    // Phase 3.3: the directory now defaults to CURRENT employees. These Phase 3.2 filter tests keep
    // their original "all rows" meaning through scope=all unless they set scope/status themselves
    // (the new default is covered in the account lifecycle tests).
    if (! str_contains($qs, 'scope=') && ! str_contains($qs, 'employment_status=')) {
        $qs .= ($qs === '' ? '?' : '&').'scope=all';
    }

    return $t->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/employees'.$qs);
}

function dir_nums($res): array
{
    return collect($res->json('data'))->pluck('employee_number')->sort()->values()->all();
}

test('each filter works alone', function () {
    $s = dir_seed();
    expect(dir_nums(dir_get($this, $s['token'])))->toBe(['E1', 'E2', 'E3', 'E4', 'E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?department_id='.$s['fin']->id)))->toBe(['E3', 'E4', 'E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?job_title_id='.$s['dev']->id)))->toBe(['E1', 'E2', 'E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?manager_id='.$s['e1']->id)))->toBe(['E3', 'E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?employment_status=terminated')))->toBe(['E4'])
        ->and(dir_nums(dir_get($this, $s['token'], '?employment_status=on_leave')))->toBe(['E2']);
});

test('search matches name, number and email in English and Arabic, and a full-name phrase', function () {
    $s = dir_seed();
    expect(dir_nums(dir_get($this, $s['token'], '?search=sara')))->toBe(['E3'])
        ->and(dir_nums(dir_get($this, $s['token'], '?search=SARA%20ali')))->toBe(['E3'])
        ->and(dir_nums(dir_get($this, $s['token'], '?search=e4')))->toBe(['E4'])
        ->and(dir_nums(dir_get($this, $s['token'], '?search=e4%40example')))->toBe(['E4'])
        ->and(dir_nums(dir_get($this, $s['token'], '?search='.rawurlencode('أحمد'))))->toBe(['E2'])
        ->and(dir_nums(dir_get($this, $s['token'], '?search='.rawurlencode('محمد علي'))))->toBe(['E5']);
});

test('filters combine with AND semantics', function () {
    $s = dir_seed();
    expect(dir_nums(dir_get($this, $s['token'], '?department_id='.$s['fin']->id.'&employment_status=active')))->toBe(['E3', 'E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?department_id='.$s['fin']->id.'&job_title_id='.$s['dev']->id)))->toBe(['E5'])
        ->and(dir_nums(dir_get($this, $s['token'], '?department_id='.$s['fin']->id.'&job_title_id='.$s['acc']->id.'&manager_id='.$s['e1']->id.'&employment_status=active&search=sara')))->toBe(['E3'])
        ->and(dir_nums(dir_get($this, $s['token'], '?department_id='.$s['eng']->id.'&employment_status=terminated')))->toBe([]);
});

test('empty filter values are ignored (all rows)', function () {
    $s = dir_seed();
    expect(dir_nums(dir_get($this, $s['token'], '?search=&department_id=&job_title_id=&manager_id=&employment_status=&scope=all')))->toBe(['E1', 'E2', 'E3', 'E4', 'E5']);
});

test('LIKE wildcards in search are literal', function () {
    $s = dir_seed();
    expect(dir_nums(dir_get($this, $s['token'], '?search=%25')))->toBe([])
        ->and(dir_nums(dir_get($this, $s['token'], '?search=_')))->toBe([]);
});

test('malformed filters return a field-level 422, never a 500 or a silent empty list', function (string $qs, string $field) {
    $s = dir_seed();
    dir_get($this, $s['token'], $qs)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['?department_id=abc', 'department_id'],
    ['?job_title_id=1.5', 'job_title_id'],
    ['?manager_id=99999999999999999999', 'manager_id'],
    ['?department_id[]=1', 'department_id'],
    ['?employment_status=bogus', 'employment_status'],
    ['?employment_status=ACTIVE', 'employment_status'],
    ['?search[]=x', 'search'],
]);

test('filters never cross tenants, including with a foreign department id', function () {
    $a = dir_seed();
    $b = dir_seed();
    expect(dir_nums(dir_get($this, $a['token'], '?department_id='.$b['fin']->id)))->toBe([])
        ->and(dir_nums(dir_get($this, $a['token'], '?manager_id='.$b['e1']->id)))->toBe([])
        ->and(dir_nums(dir_get($this, $a['token'], '?search=sara')))->toBe(['E3'])
        ->and(dir_get($this, $a['token'])->json('data'))->toHaveCount(5);
});

test('soft-deleted employees never appear in the directory', function () {
    $s = dir_seed();
    $this->withHeader('Authorization', 'Bearer '.$s['token'])->deleteJson('/api/v1/employees/'.$s['e4']->id)->assertSuccessful();
    expect(dir_nums(dir_get($this, $s['token'])))->toBe(['E1', 'E2', 'E3', 'E5']);
});
