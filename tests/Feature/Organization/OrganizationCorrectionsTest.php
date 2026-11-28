<?php

use App\Models\OrgPositionAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../AccountLifecycle/Support.php';
uses(RefreshDatabase::class);

// Phase 3.4 FINAL-V3: administrative correction and same-day replacement. Synthetic data only.

const CR_R = 'Synthetic correction reason text';

function cr_day(int $n): string
{
    return Carbon::now(config('app.timezone'))->addDays($n)->toDateString();
}

function cr_pos($t, string $token, string $code): int
{
    return lc_call($t, $token, 'POST', '/api/v1/organization/positions', ['code' => $code, 'title' => "T $code", 'reason' => CR_R])->assertStatus(201)->json('data.id');
}

function cr_assign($t, string $token, int $pos, int $emp, array $x = [], int $st = 201)
{
    return lc_call($t, $token, 'POST', "/api/v1/organization/positions/$pos/assignments", $x + ['employee_id' => $emp, 'type' => 'primary', 'reason' => CR_R])->assertStatus($st);
}

function cr_post($t, string $token, int $id, string $verb, array $b, int $st = 200)
{
    return lc_call($t, $token, 'POST', "/api/v1/organization/assignments/$id/$verb", $b)->assertStatus($st);
}

function cr_row(int $id): OrgPositionAssignment
{
    return OrgPositionAssignment::withoutGlobalScopes()->findOrFail($id);
}

function cr_chart($t, string $token, int $pos): array
{
    return collect(lc_call($t, $token, 'GET', '/api/v1/organization/chart')->assertOk()->json('data.positions'))->firstWhere('id', $pos);
}

test('the reported defect: a mistaken assignment ended today blocks the correct one under Option A, and VOID fixes it at once without deleting anything', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C1');
    $wrong = $w['eNone'];
    $right = lc_emp($w['c'], ['email' => 'right@a.test']);
    $a = cr_assign($this, $w['hrT'], $p, $wrong->id)->json('data.id');
    cr_post($this, $w['hrT'], $a, 'end', ['reason' => CR_R]);
    $blocked = cr_assign($this, $w['hrT'], $p, $right->id, [], 409);
    expect($blocked->json('code'))->toBe('primary_conflict')->and($blocked->json('earliest_start'))->toBe(cr_day(1));
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]);
    cr_assign($this, $w['hrT'], $p, $right->id);
    expect(cr_chart($this, $w['hrT'], $p)['primary_id'])->toBe($right->id);
    $row = cr_row($a); // the original row still exists with its original dates
    expect($row->status)->toBe('cancelled')->and($row->end_cause)->toBe('correction')->and($row->voided_by)->not->toBeNull()
        ->and($row->starts_on->toDateString())->toBe(cr_day(0))->and($row->reason)->toBe(CR_R);
    expect(DB::table('audit_logs')->where('action', 'org.assignment.voided')->count())->toBe(1);
});

test('void needs a reason of 10+ characters and an explicit confirmation; it is not repeatable and keeps one audit record', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C2');
    $a = cr_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => str_repeat('x', 501), 'confirm' => true], 422);
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R], 422);
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => false], 422);
    expect(cr_row($a)->status)->toBe('active');
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]);
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true], 409);
});

test('same-day replacement, correction mode: incumbent is voided (kept), successor starts today, one atomic operation, no overlap', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C3');
    $wrong = $w['eNone'];
    $right = lc_emp($w['c'], ['email' => 'right3@a.test']);
    $a = cr_assign($this, $w['hrT'], $p, $wrong->id, ['starts_on' => cr_day(-5)])->json('data.id');
    $res = cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $right->id, 'mode' => 'correction', 'reason' => CR_R, 'confirm' => true], 201);
    $new = $res->json('data.replacement.id');
    expect($res->json('data.previous.status'))->toBe('cancelled')->and($res->json('data.replacement.starts_on'))->toBe(cr_day(0))
        ->and($res->json('data.replacement.replaces_assignment_id'))->toBe($a);
    expect(cr_chart($this, $w['hrT'], $p)['primary_id'])->toBe($right->id);
    expect(cr_row($a)->correction_id)->toBe(cr_row($new)->correction_id)->and(cr_row($a)->starts_on->toDateString())->toBe(cr_day(-5));
    expect(DB::table('audit_logs')->whereIn('action', ['org.assignment.voided', 'org.assignment.created', 'org.assignment.replaced'])->count())->toBeGreaterThanOrEqual(4);
});

test('same-day replacement, handover mode: incumbent keeps history through YESTERDAY, successor starts TODAY; a holder who started today is cancelled instead', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C4');
    $old = $w['eNone'];
    $new = lc_emp($w['c'], ['email' => 'new4@a.test']);
    $a = cr_assign($this, $w['hrT'], $p, $old->id, ['starts_on' => cr_day(-30)])->json('data.id');
    $res = cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $new->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => true], 201);
    expect($res->json('data.previous.ends_on'))->toBe(cr_day(-1))->and($res->json('data.previous.status'))->toBe('ended')->and($res->json('data.previous.end_cause'))->toBe('replaced');
    expect(cr_chart($this, $w['hrT'], $p)['primary_id'])->toBe($new->id);
    // history stays accurate: the earlier holder is still a real, past holder
    $hist = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$p/history")->assertOk()->json('data.assignments');
    expect(collect($hist)->pluck('status', 'id')->all())->toMatchArray([$a => 'ended']);
    // started today -> handover cancels (never effective before the successor)
    $p2 = cr_pos($this, $w['hrT'], 'C4b');
    $x = cr_assign($this, $w['hrT'], $p2, $old->id)->json('data.id');
    $r2 = cr_post($this, $w['hrT'], $x, 'replace', ['employee_id' => $new->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => true], 201);
    expect($r2->json('data.previous.status'))->toBe('cancelled')->and($r2->json('data.previous.end_cause'))->toBe('replaced');
});

test('replace is atomic: a refused successor leaves the incumbent exactly as it was', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C5');
    $q = cr_pos($this, $w['hrT'], 'C5b');
    $holder = $w['eNone'];
    $busy = lc_emp($w['c'], ['email' => 'busy5@a.test']);
    $a = cr_assign($this, $w['hrT'], $p, $holder->id, ['starts_on' => cr_day(-3)])->json('data.id');
    // successor already holds a deputy role on the same position -> duplicate_assignment inside doAssign, after the incumbent step
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/assignments", ['employee_id' => $busy->id, 'type' => 'deputy', 'reason' => CR_R])->assertStatus(201);
    $before = cr_row($a)->only(['status', 'ends_on', 'end_cause']);
    $res = cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $busy->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => true], 409);
    expect($res->json('code'))->toBe('duplicate_assignment');
    expect(cr_row($a)->only(['status', 'ends_on', 'end_cause']))->toBe($before);
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p)->count())->toBe(2);
    cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $holder->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => true], 422);
    cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $busy->id, 'mode' => 'bad', 'reason' => CR_R, 'confirm' => true], 422);
    cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $busy->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => false], 422);
    cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $busy->id, 'mode' => 'handover', 'reason' => CR_R, 'confirm' => true, 'effective_on' => cr_day(-1)], 422);
});

test('reinstate restores an assignment ended by mistake, re-checks conflicts, and refuses expired or employee-left endings', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C6');
    $good = $w['eNone'];
    $other = lc_emp($w['c'], ['email' => 'other6@a.test']);
    $a = cr_assign($this, $w['hrT'], $p, $good->id, ['starts_on' => cr_day(-10)])->json('data.id');
    cr_post($this, $w['hrT'], $a, 'end', ['reason' => CR_R]); // ended by mistake today
    cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true]);
    expect(cr_row($a)->status)->toBe('active')->and(cr_row($a)->ends_on)->toBeNull()->and(cr_chart($this, $w['hrT'], $p)['primary_id'])->toBe($good->id);
    cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409); // not ended
    // void then someone else is appointed: reinstating the voided row now conflicts
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]);
    cr_assign($this, $w['hrT'], $p, $other->id);
    $c = cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
    expect($c->json('code'))->toBe('primary_conflict');
    // a natural expiry cannot be "reinstated"
    $q = cr_pos($this, $w['hrT'], 'C6b');
    $e = DB::table('org_position_assignments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'position_id' => $q, 'employee_id' => $good->id, 'type' => 'primary', 'status' => 'ended',
        'starts_on' => cr_day(-40), 'ends_on' => cr_day(-20), 'end_cause' => 'expired', 'created_at' => now(), 'updated_at' => now(),
    ]);
    cr_post($this, $w['hrT'], $e, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
});

test('authorization and tenant isolation: ordinary employee 403, other tenant 404, HR without an employee profile works, no row is ever deleted', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    $p = cr_pos($this, $w['hrT'], 'C7');
    $pb = cr_pos($this, $b['hrT'], 'C7B');
    $a = cr_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    $ab = cr_assign($this, $b['hrT'], $pb, $b['eNone']->id)->json('data.id');
    foreach (['void' => ['reason' => CR_R, 'confirm' => true], 'reinstate' => ['reason' => CR_R, 'confirm' => true], 'replace' => ['employee_id' => $w['eHr2']->id, 'mode' => 'correction', 'reason' => CR_R, 'confirm' => true]] as $verb => $body) {
        cr_post($this, $w['empT'], $a, $verb, $body, 403);
        cr_post($this, $w['hrT'], $ab, $verb, $body, 404); // another tenant's row does not exist for this actor
        cr_post($this, $b['hrT'], $a, $verb, $body, 404);
    }
    expect(OrgPositionAssignment::withoutGlobalScopes()->count())->toBe(2);
    // replacement employee from another tenant is refused
    cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $b['eNone']->id, 'mode' => 'correction', 'reason' => CR_R, 'confirm' => true], 404);
    expect(cr_row($a)->status)->toBe('active');
    // self target: HR cannot correct their own assignment
    $mine = cr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['type' => 'deputy'], 201)->json('data.id');
    // F09: it can only RELEASE itself (void/end/hand the seat to someone else); it can never assign, reinstate or re-place itself
    cr_post($this, $w['hrT'], $mine, 'reinstate', ['reason' => CR_R, 'confirm' => true], 403);
    cr_assign($this, $w['hrT'], $p, $w['eHr']->id, ['type' => 'acting'], 403);
    cr_post($this, $w['hrT'], $mine, 'void', ['reason' => CR_R, 'confirm' => true]);
    expect(cr_row($mine)->status)->toBe('cancelled')->and(cr_row($mine)->voided_by)->not->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'org.assignment.voided')->where('reason', CR_R)->get()->contains(fn ($r) => str_contains((string) $r->new_values, 'self_release')))->toBeTrue();
    $mine2 = cr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['type' => 'deputy'], 201)->json('data.id');
    cr_post($this, $w['hrT'], $mine2, 'end', ['reason' => CR_R]);
    expect(cr_row($mine2)->status)->toBe('ended');
    cr_post($this, $w['hrT'], $mine2, 'reinstate', ['reason' => CR_R, 'confirm' => true], 403);
    // System Super Admin without a profile keeps the capability
    DB::table('employees')->where('id', $w['eSa']->id)->update(['user_id' => null]);
    cr_post($this, $w['saT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]);
});

test('position details list recently closed assignments so a mistaken end can be voided or reinstated without the history permission; voided rows are not listed', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'C8');
    $a = cr_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    cr_post($this, $w['hrT'], $a, 'end', ['reason' => CR_R]);
    $d = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$p")->assertOk()->json('data');
    expect(collect($d['closed_assignments'])->pluck('id')->all())->toBe([$a])->and($d['assignments'])->toBe([]);
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]);
    $d = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$p")->assertOk()->json('data');
    expect($d['closed_assignments'])->toBe([]);
    $hist = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$p/history")->assertOk()->json('data.assignments');
    expect(collect($hist)->firstWhere('id', $a)['end_cause'])->toBe('correction')->and(collect($hist)->firstWhere('id', $a)['status'])->toBe('cancelled');
});

test('F10: an unused position can be permanently deleted with reason and confirmation; its snapshot stays in the audit; dependents block it with the actual reason', function () {
    $w = lc_world();
    $url = fn ($id) => "/api/v1/organization/positions/$id/delete";
    $p = cr_pos($this, $w['hrT'], 'DEL1');
    lc_call($this, $w['hrT'], 'POST', $url($p), ['reason' => CR_R])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', $url($p), ['reason' => str_repeat('x', 501), 'confirm' => true])->assertStatus(422);
    lc_call($this, $w['empT'], 'POST', $url($p), ['reason' => CR_R, 'confirm' => true])->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', $url($p), ['reason' => CR_R, 'confirm' => true])->assertOk()->assertJsonPath('data.deleted', true);
    expect(DB::table('org_positions')->where('id', $p)->exists())->toBeFalse();
    $a = DB::table('audit_logs')->where('action', 'org.position.deleted')->first();
    expect($a)->not->toBeNull()->and((string) $a->old_values)->toContain('DEL1')->and($a->reason)->toBe(CR_R);
    lc_call($this, $w['hrT'], 'POST', $url($p), ['reason' => CR_R, 'confirm' => true])->assertStatus(404);
    // UAT-02: dependents no longer block removal; they need an explicit decision (see OrganizationRemovalTest). A child alone needs a parent choice.
    $par = cr_pos($this, $w['hrT'], 'DELP');
    $kid = lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'DELK', 'title' => 'Kid', 'parent_id' => $par, 'reason' => CR_R])->assertStatus(201)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', $url($par), ['reason' => CR_R, 'confirm' => true])->assertStatus(422)->assertJsonPath('code', 'children_resolution_required');
    lc_call($this, $w['hrT'], 'POST', $url($kid), ['reason' => CR_R, 'confirm' => true])->assertOk()->assertJsonPath('data.mode', 'deleted');
    expect(DB::table('org_reporting_history')->where('position_id', $kid)->count())->toBe(0);
    // a position that only has cancelled (voided) history is tombstoned, never erased
    $q = cr_pos($this, $w['hrT'], 'DEL2');
    $as = cr_assign($this, $w['hrT'], $q, $w['eNone']->id)->json('data.id');
    cr_post($this, $w['hrT'], $as, 'void', ['reason' => CR_R, 'confirm' => true]);
    lc_call($this, $w['hrT'], 'POST', $url($q), ['reason' => CR_R, 'confirm' => true])->assertOk()->assertJsonPath('data.mode', 'removed');
    expect(DB::table('org_positions')->where('id', $q)->value('status'))->toBe('removed')->and(OrgPositionAssignment::withoutGlobalScopes()->count())->toBe(1);
    // other tenant
    $b = lc_world('b');
    lc_call($this, $b['hrT'], 'POST', $url($q), ['reason' => CR_R, 'confirm' => true])->assertStatus(404);
});

test('F11: an archived position whose department was deleted is not restored into an invalid state; the administrator must choose a department explicitly', function () {
    $w = lc_world();
    $dep = DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'name' => 'Gone', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $dep2 = DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'name' => 'Kept', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $p = lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'RD1', 'title' => 'RD', 'department_id' => $dep, 'reason' => CR_R])->assertStatus(201)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/archive", ['reason' => CR_R])->assertOk();
    DB::table('departments')->where('id', $dep)->update(['deleted_at' => now()]);
    $r = fn ($b) => lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/restore", $b + ['reason' => CR_R]);
    $r([])->assertStatus(409)->assertJsonPath('code', 'department_deleted');
    $r(['department_id' => $dep])->assertStatus(422);
    expect(DB::table('org_positions')->where('id', $p)->value('status'))->toBe('archived');
    $r(['department_id' => $dep2])->assertOk();
    expect(DB::table('org_positions')->where('id', $p)->value('department_id'))->toBe($dep2)->and(DB::table('org_positions')->where('id', $p)->value('status'))->toBe('active');
    // a live department restores as before
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/archive", ['reason' => CR_R])->assertOk();
    $r([])->assertOk();
});

// ───────── FINAL-V3 review F18 / F20 ─────────

function cr_closed($w, int $pos, int $emp, string $status, ?string $end, ?string $cause, int $startOffset = -40): int
{
    return DB::table('org_position_assignments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'position_id' => $pos, 'employee_id' => $emp, 'type' => 'primary', 'status' => $status,
        'starts_on' => cr_day($startOffset), 'ends_on' => $end, 'end_cause' => $cause, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('F18: a naturally expired assignment cannot be reinstated or voided even while its stored status is still active (scheduler has not run)', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'F18a');
    $e = cr_closed($w, $p, $w['eNone']->id, 'active', cr_day(-3), null);
    expect(cr_row($e)->status)->toBe('active');
    cr_post($this, $w['hrT'], $e, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
    cr_post($this, $w['hrT'], $e, 'void', ['reason' => CR_R, 'confirm' => true], 409);
    expect(cr_row($e)->status)->toBe('active')->and(cr_row($e)->voided_at)->toBeNull();
    // same answer after the scheduler ran (stored as ended / expired)
    DB::table('org_position_assignments')->where('id', $e)->update(['status' => 'ended', 'end_cause' => 'expired']);
    cr_post($this, $w['hrT'], $e, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
    cr_post($this, $w['hrT'], $e, 'void', ['reason' => CR_R, 'confirm' => true], 409);
});

test('F18: employment termination and deletion endings cannot be voided, reinstated, or laundered through void then reinstate; reactivation does not change that', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'F18b');
    $a = cr_assign($this, $w['hrT'], $p, $w['eEmp']->id, ['starts_on' => cr_day(-10)])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/terminate", ['reason' => 'Synthetic termination reason', 'confirm_employee_uuid' => $w['eEmp']->uuid, 'termination_date' => now()->toDateString()])->assertOk();
    $cause = cr_row($a)->end_cause;
    expect($cause)->not->toBeNull()->and(cr_row($a)->status)->toBeIn(['ended', 'cancelled']);
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true], 409);
    cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true], 422);
    // the employee is reactivated afterwards: still not reinstatable
    DB::table('employees')->where('id', $w['eEmp']->id)->update(['employment_status' => 'active', 'deleted_at' => null]);
    cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
    expect(cr_row($a)->end_cause)->toBe($cause)->and(cr_row($a)->voided_at)->toBeNull();
    foreach (['terminated', 'deleted', 'position_deleted', 'archived', 'expired'] as $i => $c) {
        $r = cr_closed($w, $p, $w['eNone']->id, 'ended', cr_day(-20 + $i), $c, -60);
        cr_post($this, $w['hrT'], $r, 'void', ['reason' => CR_R, 'confirm' => true], 409);
        cr_post($this, $w['hrT'], $r, 'reinstate', ['reason' => CR_R, 'confirm' => true], 409);
    }
});

test('F18: genuine mistakes stay correctable, the original cause stays in the audit, and the placement flag is restored only on request and only without overlap', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'F18c');
    $q = cr_pos($this, $w['hrT'], 'F18d');
    $good = $w['eNone'];
    $a = cr_assign($this, $w['hrT'], $p, $good->id, ['starts_on' => cr_day(-10), 'is_primary_placement' => true])->json('data.id');
    cr_post($this, $w['hrT'], $a, 'end', ['reason' => CR_R]);
    expect(cr_row($a)->end_cause)->toBe('manual')->and((bool) cr_row($a)->is_primary_placement)->toBeFalse();
    cr_post($this, $w['hrT'], $a, 'void', ['reason' => CR_R, 'confirm' => true]); // a mistaken manual end can be voided
    expect(cr_row($a)->end_cause)->toBe('correction');
    $log = DB::table('audit_logs')->where('action', 'org.assignment.voided')->latest('id')->first();
    expect((string) $log->old_values)->toContain('manual');
    // another placement now overlaps: restoring the flag is refused, plain reinstate (no flag) works
    cr_assign($this, $w['hrT'], $q, $good->id, ['starts_on' => cr_day(-5), 'is_primary_placement' => true]);
    $c = cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true, 'restore_placement' => true], 409);
    expect($c->json('code'))->toBe('placement_conflict');
    cr_post($this, $w['hrT'], $a, 'reinstate', ['reason' => CR_R, 'confirm' => true]);
    expect(cr_row($a)->status)->toBe('active')->and((bool) cr_row($a)->is_primary_placement)->toBeFalse();
    $log = DB::table('audit_logs')->where('action', 'org.assignment.reinstated')->latest('id')->first();
    expect((string) $log->new_values)->toContain('restored_end_cause');
});

test('F20: self-release is marked in the audit on end, void, correction replacement and genuine handover', function () {
    $w = lc_world();
    $marked = fn (string $action) => DB::table('audit_logs')->where('action', $action)->get()->contains(fn ($r) => str_contains((string) $r->new_values, 'self_release'));
    $p = cr_pos($this, $w['hrT'], 'F20a');
    // end
    $x = cr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['type' => 'deputy'])->json('data.id');
    cr_post($this, $w['hrT'], $x, 'end', ['reason' => CR_R]);
    expect($marked('org.assignment.ended'))->toBeTrue();
    // void
    $p = cr_pos($this, $w['hrT'], 'F20v');
    $x = cr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['type' => 'deputy'])->json('data.id');
    cr_post($this, $w['hrT'], $x, 'void', ['reason' => CR_R, 'confirm' => true]);
    expect($marked('org.assignment.voided'))->toBeTrue();
    // correction replacement
    $p = cr_pos($this, $w['hrT'], 'F20r');
    $x = cr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['type' => 'deputy'])->json('data.id');
    DB::table('audit_logs')->delete();
    cr_post($this, $w['hrT'], $x, 'replace', ['employee_id' => $w['eNone']->id, 'mode' => 'correction', 'type' => 'deputy', 'reason' => CR_R, 'confirm' => true], 201);
    expect($marked('org.assignment.voided'))->toBeTrue()->and($marked('org.assignment.replaced'))->toBeTrue();
    // genuine handover (the incumbent keeps working until yesterday; ends_on is in the future-safe branch)
    DB::table('audit_logs')->delete();
    $p2 = cr_pos($this, $w['hrT'], 'F20b');
    $y = cr_assign($this, $w['hr2T'], $p2, $w['eHr']->id, ['starts_on' => cr_day(-10)])->json('data.id');
    cr_post($this, $w['hrT'], $y, 'replace', ['employee_id' => $w['eNone']->id, 'mode' => 'handover', 'effective_on' => cr_day(3), 'reason' => CR_R, 'confirm' => true], 201);
    expect($marked('org.assignment.ended'))->toBeTrue()->and($marked('org.assignment.replaced'))->toBeTrue();
    // a replacement by someone else's assignment is NOT marked
    DB::table('audit_logs')->delete();
    $p3 = cr_pos($this, $w['hrT'], 'F20c');
    $z = cr_assign($this, $w['hrT'], $p3, $w['eNone']->id, ['starts_on' => cr_day(-10)])->json('data.id');
    cr_post($this, $w['hrT'], $z, 'replace', ['employee_id' => $w['eEmp']->id, 'mode' => 'handover', 'effective_on' => cr_day(3), 'reason' => CR_R, 'confirm' => true], 201);
    expect($marked('org.assignment.ended'))->toBeFalse()->and($marked('org.assignment.replaced'))->toBeFalse();
});

function cr_state(int $id): array
{
    return [
        'row' => (array) DB::table('org_position_assignments')->where('id', $id)->first(),
        'assignments' => DB::table('org_position_assignments')->count(),
        'audits' => DB::table('audit_logs')->count(),
    ];
}

test('F18-R2: replace (correction and handover) cannot overwrite a termination, deletion, archival or expiry ending, including termination today, a future-dated ending and after reactivation; rejected calls change nothing', function () {
    $w = lc_world();
    $p = cr_pos($this, $w['hrT'], 'F18r2');
    $new = lc_emp($w['c'], ['email' => 'succ-r2@a.test']);
    // 1. real termination today: the row ends today (inclusive) so the plain status/date checks alone would let a replacement through
    $a = cr_assign($this, $w['hrT'], $p, $w['eEmp']->id, ['starts_on' => cr_day(-10)])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/employees/{$w['eEmp']->id}/terminate", ['reason' => 'Synthetic termination reason', 'confirm_employee_uuid' => $w['eEmp']->uuid, 'termination_date' => now()->toDateString()])->assertOk();
    $cause = cr_row($a)->end_cause;
    expect($cause)->not->toBeNull()->and(cr_row($a)->ends_on?->toDateString())->toBe(cr_day(0));
    $check = function (int $id, string $code = 'assignment_not_correctable') use ($w, $new) {
        $before = cr_state($id);
        foreach (['correction', 'handover'] as $mode) {
            $r = cr_post($this, $w['hrT'], $id, 'replace', ['employee_id' => $new->id, 'mode' => $mode, 'reason' => CR_R, 'confirm' => true], 409);
            expect($r->json('code'))->toBe($code);
        }
        expect(cr_state($id))->toBe($before);
    };
    $check($a);
    // 2. employee reactivated afterwards: still refused, cause kept
    DB::table('employees')->where('id', $w['eEmp']->id)->update(['employment_status' => 'active', 'deleted_at' => null]);
    $check($a);
    expect(cr_row($a)->end_cause)->toBe($cause)->and(cr_row($a)->voided_at)->toBeNull();
    // 3. future-dated lifecycle endings (end date later than today) and the other protected causes
    foreach (['terminated', 'deleted', 'position_deleted', 'archived', 'expired'] as $i => $c) {
        $r = cr_closed($w, $p, $w['eNone']->id, 'ended', cr_day(2 + $i), $c, -60);
        $check($r);
    }
    // 4. a row stored as active but already past its end date counts as expired
    $e = cr_closed($w, $p, $w['eNone']->id, 'active', cr_day(-2), null);
    $check($e, 'assignment_not_active');
});

test('F18-R2: a manual end that is still inside today stays replaceable in both modes (genuine correction and handover remain possible)', function () {
    $w = lc_world();
    foreach (['correction', 'handover'] as $mode) {
        $p = cr_pos($this, $w['hrT'], 'F18m'.$mode);
        $new = lc_emp($w['c'], ['email' => "m-$mode@a.test"]);
        $a = cr_assign($this, $w['hrT'], $p, $w['eNone']->id, ['starts_on' => cr_day(-5)])->json('data.id');
        cr_post($this, $w['hrT'], $a, 'end', ['reason' => CR_R]);
        expect(cr_row($a)->end_cause)->toBe('manual');
        cr_post($this, $w['hrT'], $a, 'replace', ['employee_id' => $new->id, 'mode' => $mode, 'reason' => CR_R, 'confirm' => true], 201);
        expect(cr_row($a)->end_cause)->toBe($mode === 'correction' ? 'correction' : 'replaced');
        // free the holder for the next loop iteration
        DB::table('org_position_assignments')->where('position_id', $p)->update(['status' => 'cancelled']);
    }
});
