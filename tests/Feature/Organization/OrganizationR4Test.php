<?php

// FINAL-V3 R4 (UAT-01 to UAT-05): manager eligibility, dependency-aware removal, occupancy and conflict explanations, optional reasons. Synthetic data only.

use App\Models\Employee;
use App\Models\OrgPositionAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../AccountLifecycle/Support.php';

const R4_R = 'Synthetic R4 reason text';
const R4_BASE = '/api/v1/organization';

function r4_dept(int $cid, string $name): int
{
    return DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $cid, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
}

function r4_pos($t, string $token, string $code, array $x = [], int $st = 201): ?int
{
    return lc_call($t, $token, 'POST', R4_BASE.'/positions', $x + ['code' => $code, 'title' => 'Same Title'])->assertStatus($st)->json('data.id');
}

function r4_assign($t, string $token, int $pos, int $emp, array $x = [], int $st = 201)
{
    return lc_call($t, $token, 'POST', R4_BASE."/positions/$pos/assignments", $x + ['employee_id' => $emp, 'type' => 'primary'])->assertStatus($st);
}

function r4_chart_ids($t, string $token): array
{
    return collect(lc_call($t, $token, 'GET', R4_BASE.'/chart?include_archived=1')->assertOk()->json('data.positions'))->pluck('id')->all();
}

test('UAT-01: own-department eligibility makes the senior employee a candidate for a colleague, only in that department, with no extra permissions', function () {
    $w = lc_world();
    $cid = $w['c']->id;
    $d1 = r4_dept($cid, 'Dept One');
    $d2 = r4_dept($cid, 'Dept Two');
    $tSenior = DB::table('job_titles')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $cid, 'name' => 'Principal Whatever', 'is_active' => true, 'department_availability' => 'all', 'created_at' => now(), 'updated_at' => now()]);
    $a = lc_emp($w['c'], ['department_id' => $d1, 'job_title_id' => $tSenior, 'is_hod' => false, 'is_team_leader' => false, 'email' => 'a@r4.test']);
    $b = lc_emp($w['c'], ['department_id' => $d1, 'email' => 'b@r4.test']);
    $c = lc_emp($w['c'], ['department_id' => $d2, 'email' => 'c@r4.test']);
    $cand = fn (int $dept, int $exclude) => collect(lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/manager-candidates?department_id=$dept&employee_id=$exclude")->assertOk()->json('data'))->pluck('id')->all();
    expect($cand($d1, $b->id))->not->toContain($a->id); // senior title alone gives nothing
    $res = lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$a->id}/manager-scope", ['scope' => 'own'])->assertOk()->assertJsonPath('data.scope', 'own')->json('data');
    expect($cand($d1, $b->id))->toContain($a->id)->and($cand($d2, $c->id))->not->toContain($a->id);
    lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$a->id}/manager-scope")->assertOk()->assertJsonPath('data.scope', 'own')->assertJsonPath('data.legacy.any', false);
    // manager choice is accepted for B in D1 and refused for C in D2
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$b->id}", ['manager_id' => $a->id])->assertStatus(200);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$c->id}", ['manager_id' => $a->id])->assertStatus(422);
    // it follows the employee's current department and grants no permission, role or flag
    $a->forceFill(['department_id' => $d2])->save();
    expect($cand($d1, $b->id))->not->toContain($a->id)->and($cand($d2, $c->id))->toContain($a->id);
    $a->refresh();
    expect((bool) $a->is_hod)->toBeFalse()->and((bool) $a->is_team_leader)->toBeFalse()->and(DB::table('user_roles')->where('user_id', $a->user_id ?? 0)->count())->toBe(0);
    // none removes the explicit setting; legacy flags are reported separately and never changed
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$a->id}/manager-scope", ['scope' => 'none'])->assertOk();
    expect(DB::table('manager_department_scopes')->where('employee_id', $a->id)->count())->toBe(0);
    $hod = lc_emp($w['c'], ['department_id' => $d1, 'is_hod' => true, 'email' => 'h@r4.test']);
    lc_call($this, $w['hrT'], 'GET', "/api/v1/employees/{$hod->id}/manager-scope")->assertOk()->assertJsonPath('data.scope', 'none')->assertJsonPath('data.legacy.is_hod', true)->assertJsonPath('data.legacy.any', true);
    expect($cand($d1, $b->id))->toContain($hod->id);
    // terminated employees cannot receive eligibility
    $gone = lc_emp($w['c'], ['department_id' => $d1, 'employment_status' => 'terminated', 'email' => 'g@r4.test']);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$gone->id}/manager-scope", ['scope' => 'own'])->assertStatus(422);
    // self target stays refused, other tenant is invisible
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$w['eHr']->id}/manager-scope", ['scope' => 'own'])->assertStatus(403);
    $o = lc_world('o.test');
    lc_call($this, $o['hrT'], 'PUT', "/api/v1/employees/{$a->id}/manager-scope", ['scope' => 'own'])->assertStatus(404);
    expect(DB::table('audit_logs')->where('action', 'employee.manager_scope_changed')->count())->toBeGreaterThanOrEqual(2);
});

test('UAT-02: removing the mistaken duplicate position keeps the employee, the other position and the history; it vanishes from the chart, lists and assignment', function () {
    $w = lc_world();
    $p1 = r4_pos($this, $w['hrT'], 'DUP-1');
    $p2 = r4_pos($this, $w['hrT'], 'DUP-2');
    $as = r4_assign($this, $w['hrT'], $p1, $w['eNone']->id)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$as/void", ['confirm' => true])->assertOk();
    lc_call($this, $w['hrT'], 'GET', R4_BASE."/positions/$p1/removal-preview")->assertOk()->assertJsonPath('data.mode', 'cancelled_only')->assertJsonPath('data.removal', 'tombstone');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p1/delete", ['confirm' => false])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p1/delete", ['confirm' => true])->assertOk()->assertJsonPath('data.mode', 'removed');
    expect(r4_chart_ids($this, $w['hrT']))->not->toContain($p1)->and(r4_chart_ids($this, $w['hrT']))->toContain($p2);
    expect(collect(lc_call($this, $w['hrT'], 'GET', R4_BASE.'/positions?status=all')->json('data'))->pluck('id')->all())->not->toContain($p1);
    expect(collect(lc_call($this, $w['hrT'], 'GET', R4_BASE.'/positions?status=removed')->json('data'))->pluck('id')->all())->toContain($p1);
    // not assignable, not editable, not a parent
    r4_assign($this, $w['hrT'], $p1, $w['eNone']->id, [], 404);
    lc_call($this, $w['hrT'], 'PUT', R4_BASE."/positions/$p1", ['title' => 'X'])->assertStatus(404);
    r4_pos($this, $w['hrT'], 'KID', ['parent_id' => $p1], 422);
    // everything else is intact
    expect(Employee::withoutGlobalScopes()->whereKey($w['eNone']->id)->exists())->toBeTrue()->and(DB::table('org_positions')->where('id', $p2)->value('status'))->toBe('active');
    expect(DB::table('org_positions')->where('id', $p1)->value('status'))->toBe('removed')->and(OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p1)->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'org.position.removed')->count())->toBe(1)->and(DB::table('audit_logs')->where('action', 'org.assignment.voided')->count())->toBe(1);
    lc_call($this, $w['hrT'], 'GET', R4_BASE."/positions/$p1/history")->assertOk();
    // the code stays reserved while removed; restore brings it back
    r4_pos($this, $w['hrT'], 'DUP-1', [], 409);
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p1/restore", [])->assertOk();
    expect(r4_chart_ids($this, $w['hrT']))->toContain($p1);
    // other tenant and missing permission
    $o = lc_world('o.test');
    lc_call($this, $o['hrT'], 'GET', R4_BASE."/positions/$p1/removal-preview")->assertStatus(404);
    lc_call($this, $w['empT'], 'POST', R4_BASE."/positions/$p1/delete", ['confirm' => true])->assertStatus(403);
});

test('UAT-02: current assignments and children need explicit decisions, applied atomically; legitimate history is never reclassified', function () {
    $w = lc_world();
    $e1 = lc_emp($w['c'], ['email' => 'e1@r4.test']);
    $e2 = lc_emp($w['c'], ['email' => 'e2@r4.test']);
    $top = r4_pos($this, $w['hrT'], 'TOP');
    $p = r4_pos($this, $w['hrT'], 'MID', ['parent_id' => $top]);
    $kid = r4_pos($this, $w['hrT'], 'KID', ['parent_id' => $p]);
    $dest = r4_pos($this, $w['hrT'], 'DEST');
    $a1 = r4_assign($this, $w['hrT'], $p, $e1->id)->json('data.id');
    $a2 = r4_assign($this, $w['hrT'], $p, $e2->id, ['type' => 'deputy'])->json('data.id');
    $url = R4_BASE."/positions/$p/delete";
    $prev = lc_call($this, $w['hrT'], 'GET', R4_BASE."/positions/$p/removal-preview")->assertOk()->assertJsonPath('data.mode', 'current')->json('data');
    expect($prev['live_assignments'])->toHaveCount(2)->and($prev['children'])->toHaveCount(1);
    lc_call($this, $w['hrT'], 'POST', $url, ['confirm' => true])->assertStatus(422)->assertJsonPath('code', 'resolution_required');
    $res = [['assignment_id' => $a1, 'action' => 'move', 'target_position_id' => $dest], ['assignment_id' => $a2, 'action' => 'end']];
    lc_call($this, $w['hrT'], 'POST', $url, ['confirm' => true, 'resolutions' => $res])->assertStatus(422)->assertJsonPath('code', 'children_resolution_required');
    // atomic: a bad move target rolls everything back
    lc_call($this, $w['hrT'], 'POST', $url, ['confirm' => true, 'resolutions' => [['assignment_id' => $a1, 'action' => 'move', 'target_position_id' => 999999], ['assignment_id' => $a2, 'action' => 'end']], 'children' => ['parent_id' => $top]])->assertStatus(404);
    expect(DB::table('org_positions')->where('id', $p)->value('status'))->toBe('active')->and(DB::table('org_position_assignments')->where('id', $a2)->value('status'))->toBe('active');
    // cycle: the new parent cannot be below the child
    lc_call($this, $w['hrT'], 'POST', $url, ['confirm' => true, 'resolutions' => $res, 'children' => ['parent_id' => $kid]])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', $url, ['confirm' => true, 'resolutions' => $res, 'children' => ['parent_id' => null]])->assertOk()->assertJsonPath('data.mode', 'removed')
        ->assertJsonPath('data.summary.moved', 1)->assertJsonPath('data.summary.ended', 1)->assertJsonPath('data.summary.children_reparented', 1);
    expect(DB::table('org_positions')->where('id', $kid)->value('parent_id'))->toBeNull()->and(DB::table('org_positions')->where('id', $p)->value('status'))->toBe('removed');
    $old1 = DB::table('org_position_assignments')->where('id', $a1)->first();
    expect($old1->status)->toBe('ended')->and($old1->end_cause)->toBe('position_removed');
    expect(DB::table('org_position_assignments')->where('position_id', $dest)->where('employee_id', $e1->id)->where('status', 'active')->count())->toBe(1);
    expect(DB::table('org_position_assignments')->where('id', $a2)->value('end_cause'))->toBe('position_removed');
    expect(Employee::withoutGlobalScopes()->whereKey([$e1->id, $e2->id])->count())->toBe(2)->and(r4_chart_ids($this, $w['hrT']))->not->toContain($p)->and(r4_chart_ids($this, $w['hrT']))->toContain($kid);
    // an ended assignment of a removed position cannot be reinstated into it
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$a1/reinstate", ['confirm' => true])->assertStatus(409);
    // void resolution (a mistake) is recorded as a correction
    $q = r4_pos($this, $w['hrT'], 'MIST');
    $m = r4_assign($this, $w['hrT'], $q, $e2->id)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$q/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $m, 'action' => 'void']]])->assertOk()->assertJsonPath('data.summary.voided', 1);
    expect(DB::table('org_position_assignments')->where('id', $m)->value('end_cause'))->toBe('correction');
    // unused positions are still erased
    $u = r4_pos($this, $w['hrT'], 'UNUSED');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$u/delete", ['confirm' => true])->assertOk()->assertJsonPath('data.mode', 'deleted');
    expect(DB::table('org_positions')->where('id', $u)->exists())->toBeFalse();
});

test('UAT-03/04: conflicts carry the occupancy, the blocking assignment and only the actions the server accepts; correcting works the same day', function () {
    $w = lc_world();
    $e1 = lc_emp($w['c'], ['email' => 'c1@r4.test']);
    $e2 = lc_emp($w['c'], ['email' => 'c2@r4.test']);
    $p = r4_pos($this, $w['hrT'], 'SINGLE');
    $a = r4_assign($this, $w['hrT'], $p, $e1->id)->json('data.id');
    $j = r4_assign($this, $w['hrT'], $p, $e2->id, [], 409)->assertJsonPath('code', 'primary_conflict')->json();
    expect($j['occupancy_type'])->toBe('single')->and($j['current_primary'])->toBe(1)->and($j['conflicts'])->toHaveCount(1)
        ->and($j['conflicts'][0]['assignment_id'])->toBe($a)->and($j['conflicts'][0]['actions'])->toContain('end', 'void', 'correct')->and($j['conflicts'][0]['employee_name'])->not->toBeEmpty();
    // wrongly end, then assign the same person again: the ended row blocks it today and offers reinstate and void, not "wait"
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$a/end", [])->assertOk();
    $j2 = r4_assign($this, $w['hrT'], $p, $e1->id, [], 409)->assertJsonPath('code', 'duplicate_assignment')->json();
    expect($j2['conflicts'][0]['status'])->toBe('ended')->and($j2['conflicts'][0]['actions'])->toBe(['void', 'reinstate'])->and($j2)->toHaveKey('earliest_start');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$a/reinstate", ['confirm' => true])->assertOk();
    expect(DB::table('org_position_assignments')->where('id', $a)->value('status'))->toBe('active')->and(DB::table('audit_logs')->where('action', 'org.assignment.reinstated')->count())->toBe(1);
    // lifecycle-ended (protected) assignments offer no correction
    $e3 = lc_emp($w['c'], ['email' => 'c3@r4.test']);
    $q = r4_pos($this, $w['hrT'], 'LIFE');
    $l = r4_assign($this, $w['hrT'], $q, $e3->id)->json('data.id');
    DB::table('org_position_assignments')->where('id', $l)->update(['status' => 'ended', 'ends_on' => now()->toDateString(), 'end_cause' => 'employee_terminated']);
    $j3 = r4_assign($this, $w['hrT'], $q, $e3->id, [], 409)->json();
    expect($j3['conflicts'][0]['actions'])->toBe([]);
    // multiple position: unlimited accepts two primaries; limited reports capacity with the holders
    $m = r4_pos($this, $w['hrT'], 'MULTI', ['occupancy_type' => 'multiple']);
    r4_assign($this, $w['hrT'], $m, $e1->id);
    r4_assign($this, $w['hrT'], $m, $e2->id);
    r4_assign($this, $w['hrT'], $m, $e1->id, [], 409)->assertJsonPath('code', 'duplicate_assignment');
    $c = r4_pos($this, $w['hrT'], 'CAP', ['occupancy_type' => 'multiple', 'capacity' => 1]);
    r4_assign($this, $w['hrT'], $c, $e1->id);
    $j4 = r4_assign($this, $w['hrT'], $c, $e2->id, [], 409)->assertJsonPath('code', 'capacity_full')->json();
    expect($j4['capacity'])->toBe(1)->and($j4['current_primary'])->toBe(1)->and($j4['conflicts'])->toHaveCount(1);
});

test('UAT-05: reasons are optional for every in-scope organization operation, normalized to null, audited, and never invented', function () {
    $w = lc_world();
    $e1 = lc_emp($w['c'], ['email' => 'r1@r4.test']);
    $e2 = lc_emp($w['c'], ['email' => 'r2@r4.test']);
    $top = r4_pos($this, $w['hrT'], 'RTOP');
    $p = r4_pos($this, $w['hrT'], 'RPOS');
    lc_call($this, $w['hrT'], 'PUT', R4_BASE."/positions/$p", ['title' => 'Renamed', 'reason' => '   '])->assertOk();
    $a = r4_assign($this, $w['hrT'], $p, $e1->id)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$a/placement", [])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p/reparent", ['parent_id' => $top])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$a/replace", ['employee_id' => $e2->id, 'mode' => 'handover', 'confirm' => true])->assertStatus(201);
    $b = DB::table('org_position_assignments')->where('position_id', $p)->where('employee_id', $e2->id)->value('id');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$b/end", [])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$b/reinstate", ['confirm' => true])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/assignments/$b/void", ['confirm' => true])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p/archive", [])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p/restore", [])->assertOk();
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/employees/{$e1->id}/manager-scope", ['scope' => 'all'])->assertOk();
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p/delete", ['confirm' => true, 'resolutions' => []])->assertOk();
    foreach (['org.position.created', 'org.position.updated', 'org.assignment.created', 'org.assignment.placement_set', 'org.position.reparented', 'org.assignment.replaced', 'org.assignment.ended',
        'org.assignment.reinstated', 'org.assignment.voided', 'org.position.archived', 'org.position.restored', 'employee.manager_scope_changed', 'org.position.deleted', 'org.position.removed'] as $act) {
        $n = DB::table('audit_logs')->where('action', $act)->count();
        $with = DB::table('audit_logs')->where('action', $act)->whereNotNull('reason')->count();
        expect($n)->toBeGreaterThan($act === 'org.position.deleted' ? -1 : 0)->and($with)->toBe(0);
    }
    expect(DB::table('audit_logs')->where('action', 'org.position.removed')->count() + DB::table('audit_logs')->where('action', 'org.position.deleted')->count())->toBeGreaterThan(0);
    // a supplied reason is recorded and trimmed; the maximum still applies
    $p2 = r4_pos($this, $w['hrT'], 'RPOS2', ['reason' => '  because  ']);
    expect(DB::table('audit_logs')->where('action', 'org.position.created')->latest('id')->value('reason'))->toBe('because');
    lc_call($this, $w['hrT'], 'POST', R4_BASE."/positions/$p2/archive", ['reason' => str_repeat('x', 501)])->assertStatus(422);
    // the account lifecycle reason rules are untouched
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$e2->id}/terminate", ['confirm_employee_uuid' => $e2->uuid, 'reason' => 'short'])->assertStatus(422);
});
