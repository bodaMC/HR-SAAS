<?php

// FINAL-V3: department-aware job titles and direct manager eligibility, enforced on the server.

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../AccountLifecycle/Support.php';

const EL_R = 'Synthetic eligibility reason';

function el_dept(int $cid, string $name): int
{
    return DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $cid, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
}

function el_title($t, string $token, string $name, array $x = [], int $st = 201)
{
    return lc_call($t, $token, 'POST', '/api/v1/job-titles', ['name' => $name] + $x)->assertStatus($st);
}

function el_emp($t, string $token, array $x, int $st = 201)
{
    static $n = 0;
    $n++;

    return lc_call($t, $token, 'POST', '/api/v1/employees', $x + ['employee_number' => "EL$n".Str::random(4), 'first_name' => "F$n", 'last_name' => 'L', 'email' => "el$n".Str::random(4).'@e.test'])->assertStatus($st);
}

test('job titles: existing titles stay global; specific, multi-department and global titles filter the form list and are enforced by the server', function () {
    $w = lc_world();
    $cid = $w['c']->id;
    $dc = el_dept($cid, 'Document Control');
    $hv = el_dept($cid, 'HVAC');
    $st = el_dept($cid, 'Structural');
    $global = el_title($this, $w['hrT'], 'Secretary')->assertJsonPath('data.department_availability', 'all')->json('data.id');
    $dcT = el_title($this, $w['hrT'], 'Doc Controller', ['department_availability' => 'selected', 'department_ids' => [$dc]])->json('data.id');
    $multi = el_title($this, $w['hrT'], 'Engineer', ['department_availability' => 'selected', 'department_ids' => [$hv, $st]])->assertJsonPath('data.department_ids', [$hv, $st])->json('data.id');
    el_title($this, $w['hrT'], 'Broken', ['department_availability' => 'selected'], 422);
    el_title($this, $w['hrT'], 'Broken2', ['department_availability' => 'selected', 'department_ids' => [999999]], 422);
    $ids = fn ($d) => collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/job-titles/eligible?department_id='.$d)->assertOk()->json('data'))->pluck('id')->all();
    expect($ids($dc))->toContain($global, $dcT)->not->toContain($multi)
        ->and($ids($hv))->toContain($global, $multi)->not->toContain($dcT)
        ->and($ids($st))->toContain($multi);
    // the server enforces it, whatever the client sends
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'job_title_id' => $multi], 422)->assertJsonValidationErrors('job_title_id');
    el_emp($this, $w['hrT'], ['department_id' => null, 'job_title_id' => $dcT], 422);
    $e = el_emp($this, $w['hrT'], ['department_id' => $dc, 'job_title_id' => $dcT])->json('data.id');
    // department change makes the title invalid: refused, nothing silently changed
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['department_id' => $hv])->assertStatus(422)->assertJsonValidationErrors('job_title_id');
    expect(Employee::withoutGlobalScopes()->find($e)->department_id)->toBe($dc);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['department_id' => $hv, 'job_title_id' => $multi])->assertOk();
    // inactive and deleted titles cannot be chosen
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/job-titles/$global", ['is_active' => false])->assertOk();
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'job_title_id' => $global], 422);
    // cross-tenant title
    $b = lc_world('b.test');
    $bt = el_title($this, $b['hrT'], 'Other')->json('data.id');
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'job_title_id' => $bt], 422);
});

test('job titles: a legacy incompatible employee is not silently changed or blocked on unrelated edits, and the read-only report lists it', function () {
    $w = lc_world();
    $cid = $w['c']->id;
    $d1 = el_dept($cid, 'D1');
    $d2 = el_dept($cid, 'D2');
    $t = el_title($this, $w['hrT'], 'Legacy Title')->json('data.id');
    $e = el_emp($this, $w['hrT'], ['department_id' => $d1, 'job_title_id' => $t])->json('data.id');
    lc_call($this, $w['hrT'], 'GET', '/api/v1/job-titles/compatibility-report')->assertOk()->assertJsonPath('count', 0);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/job-titles/$t", ['department_availability' => 'selected', 'department_ids' => [$d2]])->assertOk();
    expect(Employee::withoutGlobalScopes()->find($e)->job_title_id)->toBe($t); // untouched
    $r = lc_call($this, $w['hrT'], 'GET', '/api/v1/job-titles/compatibility-report')->assertOk()->json();
    expect($r['count'])->toBe(1)->and($r['data'][0]['employee_id'])->toBe($e)->and($r['data'][0]['reason'])->toBe('not_available_in_department');
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['phone' => '0100000000'])->assertOk(); // unrelated edit still works
    lc_call($this, $w['empT'], 'GET', '/api/v1/job-titles/compatibility-report')->assertStatus(403);
    lc_call($this, $w['empT'], 'PUT', "/api/v1/job-titles/$t", ['department_availability' => 'all'])->assertStatus(403);
    $b = lc_world('b.test');
    expect(lc_call($this, $b['hrT'], 'GET', '/api/v1/job-titles/compatibility-report')->json('count'))->toBe(0);
    // administrator resolves explicitly by choosing a valid title/department
    $ok = el_title($this, $w['hrT'], 'D2 Title', ['department_availability' => 'selected', 'department_ids' => [$d2]])->json('data.id');
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['department_id' => $d2, 'job_title_id' => $ok])->assertOk();
    lc_call($this, $w['hrT'], 'GET', '/api/v1/job-titles/compatibility-report')->assertJsonPath('count', 0);
});

test('managers: candidates are the department managers/team leaders, the CEO everywhere, explicit scopes; the server refuses everyone else', function () {
    $w = lc_world();
    $c = $w['c'];
    $dc = el_dept($c->id, 'Document Control');
    $ar = el_dept($c->id, 'Architecture');
    $hv = el_dept($c->id, 'HVAC');
    $mk = fn ($d, $x = []) => lc_emp($c, $x + ['department_id' => $d, 'employment_status' => 'active']);
    $dcHead = $mk($dc, ['is_hod' => true]);
    $dcTl = $mk($dc, ['is_team_leader' => true]);
    $dcStaff = $mk($dc);
    $arHead = $mk($ar, ['is_hod' => true]);
    $ceo = $mk(null, ['is_ceo' => true]);
    $engMgr = $mk($ar); // plain employee made a cross-department manager explicitly
    $cand = fn ($d, $q = '') => collect(lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/manager-candidates?department_id=$d$q")->assertOk()->json('data'))->pluck('id')->all();
    expect($cand($dc))->toContain($dcHead->id, $dcTl->id, $ceo->id)->not->toContain($dcStaff->id, $arHead->id, $engMgr->id);
    // configure explicit cross-department scope: HVAC + Document Control only
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'departments', 'department_ids' => [$hv, $dc], 'reason' => EL_R])->assertOk()->assertJsonPath('data.department_ids', [min($hv, $dc), max($hv, $dc)]);
    expect($cand($hv))->toContain($engMgr->id, $ceo->id)->not->toContain($dcHead->id)
        ->and($cand($dc))->toContain($engMgr->id)->and($cand($ar))->not->toContain($engMgr->id);
    expect(DB::table('audit_logs')->where('action', 'employee.manager_scope_changed')->count())->toBe(1);
    // legacy flags were not written by any of this
    expect(Employee::withoutGlobalScopes()->find($engMgr->id)->only(['is_hod', 'is_team_leader', 'is_ceo', 'manager_id', 'team_leader_id']))->toBe(['is_hod' => false, 'is_team_leader' => false, 'is_ceo' => false, 'manager_id' => null, 'team_leader_id' => null]);
    // server enforcement on create and update
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'manager_id' => $dcStaff->id], 422)->assertJsonValidationErrors('manager_id');
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'manager_id' => $dcHead->id]);
    el_emp($this, $w['hrT'], ['department_id' => $ar, 'manager_id' => $ceo->id]);
    $e = el_emp($this, $w['hrT'], ['department_id' => $hv, 'manager_id' => $engMgr->id])->json('data.id');
    // department change makes the manager stale: refused, not silently cleared
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['department_id' => $ar])->assertStatus(422)->assertJsonValidationErrors('manager_id');
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/$e", ['department_id' => $ar, 'manager_id' => $arHead->id])->assertOk();
    // self reporting, cycle, terminated, deleted, cross-tenant
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$dcHead->id}", ['manager_id' => $dcHead->id])->assertStatus(422);
    $sub = el_emp($this, $w['hrT'], ['department_id' => $dc, 'manager_id' => $dcHead->id])->json('data.id');
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$dcHead->id}", ['manager_id' => $ceo->id])->assertOk();
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$dcHead->id}", ['manager_id' => $sub])->assertStatus(422);
    $term = $mk($dc, ['is_team_leader' => true, 'employment_status' => 'terminated']);
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'manager_id' => $term->id], 422);
    $b = lc_world('b.test');
    $bm = lc_emp($b['c'], ['is_hod' => true]);
    el_emp($this, $w['hrT'], ['department_id' => $dc, 'manager_id' => $bm->id], 422);
    // authorization
    lc_call($this, $w['empT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'all', 'reason' => EL_R])->assertStatus(403);
    lc_call($this, $b['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'all', 'reason' => EL_R])->assertStatus(404);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eHr']->id}/manager-scope", ['scope' => 'all', 'reason' => EL_R])->assertStatus(403); // not on own record
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'departments', 'department_ids' => [], 'reason' => EL_R])->assertStatus(422);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'all', 'reason' => str_repeat('x', 501)])->assertStatus(422);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'all'])->assertOk(); // UAT-05: reason optional
    expect(DB::table('audit_logs')->where('action', 'employee.manager_scope_changed')->latest('id')->value('reason'))->toBeNull();
    // all departments scope, then none
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'all', 'reason' => EL_R])->assertOk();
    expect($cand($ar))->toContain($engMgr->id);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$engMgr->id}/manager-scope", ['scope' => 'none', 'reason' => EL_R])->assertOk();
    expect($cand($hv))->not->toContain($engMgr->id);
});

test('managers: the compatibility report lists existing relationships that no longer fit, and changes nothing', function () {
    $w = lc_world();
    $c = $w['c'];
    $d1 = el_dept($c->id, 'D1');
    $mgr = lc_emp($c, ['department_id' => $d1]);
    $e = lc_emp($c, ['department_id' => $d1, 'manager_id' => $mgr->id]);
    $r = lc_call($this, $w['hrT'], 'GET', '/api/v1/employees/manager-compatibility-report')->assertOk()->json();
    expect(collect($r['data'])->pluck('employee_id')->all())->toContain($e->id);
    expect(Employee::withoutGlobalScopes()->find($e->id)->manager_id)->toBe($mgr->id);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$e->id}", ['phone' => '0100000001'])->assertOk(); // unrelated edit not blocked
    lc_call($this, $w['empT'], 'GET', '/api/v1/employees/manager-compatibility-report')->assertStatus(403);
});

test('F19: manager candidates are capped at 100 with an explicit "more" flag, and a search narrows to the rest', function () {
    $w = lc_world();
    $c = $w['c'];
    $dc = el_dept($c->id, 'Cap Dept');
    for ($i = 0; $i < 103; $i++) {
        lc_emp($c, ['department_id' => $dc, 'employment_status' => 'active', 'is_team_leader' => true, 'first_name' => sprintf('Cap%03d', $i), 'email' => "cap$i@a.test"]);
    }
    $r = lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/manager-candidates?department_id=$dc")->assertOk();
    expect(count($r->json('data')))->toBe(100)->and($r->json('more'))->toBeTrue();
    $s = lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/manager-candidates?department_id=$dc&q=Cap102")->assertOk();
    expect(count($s->json('data')))->toBe(1)->and($s->json('more'))->toBeFalse();
    $few = lc_call($this, $w['hrT'], 'GET', '/api/v1/employees/manager-candidates?q=Cap10')->assertOk();
    expect($few->json('more'))->toBeBool();
});
