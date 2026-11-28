<?php

use App\Models\Department;
use App\Models\OrgPositionAssignment;
use App\Rbac\PermissionResolver;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../AccountLifecycle/Support.php';
uses(RefreshDatabase::class);

const ED_R = 'Synthetic organization test reason';

function ed_pos($t, string $token, string $code, array $extra = [], int $status = 201): ?int
{
    $res = lc_call($t, $token, 'POST', '/api/v1/organization/positions', ['code' => $code, 'title' => "Title $code", 'reason' => ED_R] + $extra);
    $res->assertStatus($status);

    return $status === 201 ? $res->json('data.id') : null;
}

function ed_assign($t, string $token, int $pos, int $emp, string $type = 'primary', array $extra = [], int $status = 201)
{
    $res = lc_call($t, $token, 'POST', "/api/v1/organization/positions/$pos/assignments", ['employee_id' => $emp, 'type' => $type, 'reason' => ED_R] + $extra);
    $res->assertStatus($status);

    return $res;
}

function ed_chart($t, string $token): array
{
    return lc_call($t, $token, 'GET', '/api/v1/organization/chart')->assertOk()->json('data');
}

function ed_node(array $chart, int $id): array
{
    return collect($chart['positions'])->firstWhere('id', $id);
}

// Phase 3.4 FINAL-V2, finding F01: placement and effective-date integrity. Synthetic data only.

function ed(int $days): string
{
    return Carbon::now(config('app.timezone'))->addDays($days)->toDateString();
}

function ed_row(int $id): OrgPositionAssignment
{
    return OrgPositionAssignment::withoutGlobalScopes()->findOrFail($id);
}

/** A row that naturally expired: inserted directly as it would look after its end date passed (status still active). */
function ed_expired_placement(array $w, int $posId, int $empId): int
{
    return DB::table('org_position_assignments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'position_id' => $posId, 'employee_id' => $empId, 'type' => 'primary', 'status' => 'active',
        'starts_on' => ed(-60), 'ends_on' => ed(-10), 'is_primary_placement' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('F01: a naturally expired placement does not block a new placement and is released as ended with history kept', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'E1');
    $p2 = ed_pos($this, $w['hrT'], 'E2');
    $e = $w['eNone'];
    $old = ed_expired_placement($w, $p1, $e->id);
    $res = ed_assign($this, $w['hrT'], $p2, $e->id, 'primary', ['starts_on' => ed(0), 'is_primary_placement' => true]);
    expect($res->json('data.is_primary_placement'))->toBeTrue();
    $o = ed_row($old);
    expect($o->status)->toBe('ended')->and($o->end_cause)->toBe('expired')->and((bool) $o->is_primary_placement)->toBeFalse()->and($o->ends_on->toDateString())->toBe(ed(-10));
    expect(DB::table('audit_logs')->where('action', 'org.assignment.expired')->where('auditable_id', $old)->count())->toBe(1);
    // the expired row never appears in the chart
    expect(ed_node(ed_chart($this, $w['hrT']), $p1)['vacant'])->toBeTrue();
});

test('F01: the expiry command is idempotent and releases only rows past their end date', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'C1');
    $p2 = ed_pos($this, $w['hrT'], 'C2');
    $e = $w['eNone'];
    $old = ed_expired_placement($w, $p1, $e->id);
    $cur = ed_assign($this, $w['hrT'], $p2, $e->id, 'primary', ['starts_on' => ed(-1), 'ends_on' => ed(0)])->json('data.id');
    $this->artisan('org:expire-assignments')->assertSuccessful();
    $this->artisan('org:expire-assignments')->assertSuccessful();
    expect(ed_row($old)->status)->toBe('ended')->and(ed_row($cur)->status)->toBe('active')->and((bool) ed_row($cur)->is_primary_placement)->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'org.assignment.expired')->count())->toBe(1);
});

test('F01: setting a future placement never removes the placement that is in force today; consecutive placements work', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'S1');
    $p2 = ed_pos($this, $w['hrT'], 'S2');
    $e = $w['eNone'];
    $cur = ed_assign($this, $w['hrT'], $p1, $e->id, 'primary', ['starts_on' => ed(-5)])->json('data.id'); // open-ended, auto placement
    $fut = ed_assign($this, $w['hrT'], $p2, $e->id, 'primary', ['starts_on' => ed(10)])->json('data.id');
    expect((bool) ed_row($fut)->is_primary_placement)->toBeFalse();
    // overlapping (the current one is open-ended): refused, current placement untouched
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$fut/placement", ['reason' => ED_R])->assertStatus(409)->assertJsonPath('code', 'placement_overlap');
    expect((bool) ed_row($cur)->is_primary_placement)->toBeTrue()->and((bool) ed_row($fut)->is_primary_placement)->toBeFalse();
    // schedule the current one to end before the future one starts: now they do not overlap and both keep their placement
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$cur/end", ['ends_on' => ed(9), 'reason' => ED_R])->assertOk();
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$fut/placement", ['reason' => ED_R])->assertOk();
    $c = ed_row($cur);
    expect($c->status)->toBe('active')->and($c->ends_on->toDateString())->toBe(ed(9))->and((bool) ed_row($fut)->is_primary_placement)->toBeTrue();
});

test('F01: a scheduled assignment can be cancelled before it starts; an end date before its start is refused', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'X1');
    $e = $w['eNone'];
    $fut = ed_assign($this, $w['hrT'], $p1, $e->id, 'primary', ['starts_on' => ed(20)])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$fut/end", ['ends_on' => ed(5), 'reason' => ED_R])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$fut/end", ['reason' => ED_R])->assertOk();
    $r = ed_row($fut);
    expect($r->status)->toBe('cancelled')->and((bool) $r->is_primary_placement)->toBeFalse();
    // history keeps the cancelled row
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p1)->count())->toBe(1);
});

test('F01: an assignment ending today stays effective today (inclusive dates) and a same-day replacement is still refused (Option A)', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'I1');
    $e = $w['eNone'];
    $e2 = $w['eEmp'];
    ed_assign($this, $w['hrT'], $p1, $e->id, 'primary', ['starts_on' => ed(-3), 'ends_on' => ed(0)]);
    expect(ed_node(ed_chart($this, $w['hrT']), $p1)['primary_id'])->toBe($e->id);
    $res = ed_assign($this, $w['hrT'], $p1, $e2->id, 'primary', ['starts_on' => ed(0)], 409);
    expect($res->json('code'))->toBe('primary_conflict');
    ed_assign($this, $w['hrT'], $p1, $e2->id, 'primary', ['starts_on' => ed(1)]);
});

// ───── F05 characterization: future-dated termination (Phase 3.3 behaviour is NOT changed; these tests pin what it does today) ─────

test('F05 characterization: a future termination date ends the employee now (3.3), the position reads vacant now, and replacement is blocked until the day after the date', function () {
    $w = lc_world();
    $p = ed_pos($this, $w['hrT'], 'FT1');
    $e = $w['eEmp'];
    $e2 = $w['eNone'];
    ed_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => ed(-30)]);
    $d = ed(7);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason', 'termination_date' => $d])->assertOk();
    // 3.3 rule: employment status is "terminated" immediately, whatever the date.
    expect($e->fresh()->employment_status->value)->toBe('terminated');
    // vacancy: immediate
    $n = ed_node(ed_chart($this, $w['hrT']), $p);
    expect($n['vacant'])->toBeTrue()->and($n['primary_id'])->toBeNull()->and($n['available_from'])->toBe(ed(8));
    // the stored assignment is closed at the termination date (history), not deleted
    $row = OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p)->first();
    expect($row->status)->toBe('ended')->and($row->ends_on->toDateString())->toBe($d)->and($row->end_cause)->toBe('employee_terminated');
    // replacement eligibility: before or on the date is refused with the earliest date; the day after is accepted (inclusive dates, Option A)
    $r = ed_assign($this, $w['hrT'], $p, $e2->id, 'primary', ['starts_on' => ed(0)], 409);
    expect($r->json('code'))->toBe('primary_conflict')->and($r->json('earliest_start'))->toBe(ed(8)); // the hint added in V2; the rule itself is unchanged
    expect($n['available_from'])->toBe(ed(8));
    ed_assign($this, $w['hrT'], $p, $e2->id, 'primary', ['starts_on' => ed(8)]);
});

test('F05 characterization: reactivation after a future-dated termination restores nothing and keeps the overlap protection', function () {
    $w = lc_world();
    $p = ed_pos($this, $w['hrT'], 'FT2');
    $e = $w['eEmp'];
    ed_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => ed(-30)]);
    $fut = ed_assign($this, $w['hrT'], ed_pos($this, $w['hrT'], 'FT2B'), $e->id, 'primary', ['starts_on' => ed(20)])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason', 'termination_date' => ed(7)])->assertOk();
    expect(ed_row($fut)->status)->toBe('cancelled'); // a future assignment starting after the termination date is cancelled
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/reactivate', ['reason' => 'Synthetic reactivation reason'])->assertOk();
    expect(ed_node(ed_chart($this, $w['hrT']), $p)['vacant'])->toBeTrue(); // nothing restored
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('employee_id', $e->id)->whereIn('status', ['active'])->count())->toBe(0);
    // the closed historical window still protects the position until its end date
    ed_assign($this, $w['hrT'], $p, $w['eNone']->id, 'primary', ['starts_on' => ed(1)], 409);
    // reassigning the reactivated employee to the same position inside the closed window is also an overlap
    ed_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => ed(2)], 409);
});

test('F05: HR and System Super Admin without a linked employee profile can still read the organization chart (read only, no new permission)', function () {
    $w = lc_world();
    $c = $w['c'];
    // detach the HR and System Super Admin accounts from their employee profiles: they now have a login but no employee profile
    DB::table('employees')->whereIn('id', [$w['eHr']->id, $w['eSa']->id])->update(['user_id' => null]);
    foreach ([$w['hrT'], $w['saT']] as $t) {
        lc_call($this, $t, 'GET', '/api/v1/organization/chart')->assertOk();
    }
    // a plain employee without a profile has neither a profile nor an organization permission: still refused
    [$plain, $plainT] = lc_user($c, 'employee', ['email' => 'plain.noprofile@a.test']);
    lc_call($this, $plainT, 'GET', '/api/v1/organization/chart')->assertStatus(403);
    // reading never grants writing: an HR account without a profile keeps its normal write permission, and a plain account gets none
    lc_call($this, $plainT, 'POST', '/api/v1/organization/positions', ['code' => 'NOPE', 'title' => 'x', 'reason' => ED_R])->assertStatus(403);
});

// ───── F04: employees without positions on the chart ─────

test('F04: the chart lists ordinary employees without a position, grouped by department, with no account needed and no private fields', function () {
    $w = lc_world();
    $c = $w['c'];
    app(TenantContext::class)->set($c);
    $dep = Department::factory()->create(['name' => 'Ops']);
    app(TenantContext::class)->clear();
    $x = lc_emp($c, ['first_name' => 'Nora', 'last_name' => 'Noaccount', 'email' => 'nora@a.test', 'department_id' => $dep->id]);
    $y = lc_emp($c, ['first_name' => 'Omar', 'last_name' => 'Nodept', 'email' => 'omar@a.test', 'department_id' => null]);
    // another tenant's employee never appears
    $b = lc_world('b.test');
    $p = ed_pos($this, $w['hrT'], 'UP1');
    ed_assign($this, $w['hrT'], $p, $w['eEmp']->id);
    foreach ([$w['empT'], $w['hrT']] as $t) {
        $d = ed_chart($this, $t);
        $ids = collect($d['unassigned_groups'])->flatMap(fn ($g) => collect($g['people'])->pluck('id'))->all();
        expect($ids)->toContain($x->id)->toContain($y->id)->not->toContain($w['eEmp']->id)->not->toContain($b['eNone']->id);
        $g = collect($d['unassigned_groups'])->firstWhere('department_id', $dep->id);
        expect($g['name'])->toBe('Ops')->and(collect($g['people'])->pluck('id')->all())->toBe([$x->id]);
        expect(collect($d['unassigned_groups'])->firstWhere('department_id', null)['people'])->not->toBeEmpty();
        expect(array_keys($g['people'][0]))->toBe(['id', 'name', 'job_title', 'department', 'has_picture', 'picture_version']);
    }
    // an assignment moves the employee out of the section; granting nothing
    ed_assign($this, $w['hrT'], $p, $x->id, 'deputy');
    $ids = collect(ed_chart($this, $w['empT'])['unassigned_groups'])->flatMap(fn ($g) => collect($g['people'])->pluck('id'))->all();
    expect($ids)->not->toContain($x->id);
});

test('F04: every viewer can search people without a position; tenant isolated; wildcard characters are literal', function () {
    $w = lc_world();
    lc_emp($w['c'], ['first_name' => 'Zed', 'last_name' => 'Searchable', 'email' => 'zed@a.test']);
    lc_emp($w['c'], ['first_name' => '100%', 'last_name' => 'Literal', 'email' => 'pct@a.test']);
    $b = lc_world('b.test');
    lc_emp($b['c'], ['first_name' => 'Zed', 'last_name' => 'Other', 'email' => 'zed@b.test']);
    $r = lc_call($this, $w['empT'], 'GET', '/api/v1/organization/people-without-position?search=zed')->assertOk()->json('data');
    expect(collect($r)->pluck('name')->all())->toBe(['Zed Searchable']);
    $r = lc_call($this, $w['empT'], 'GET', '/api/v1/organization/people-without-position?search='.urlencode('%'))->assertOk()->json('data');
    expect(collect($r)->pluck('name')->all())->toBe(['100% Literal']);
});

// ───── Accounts without an employee profile (separation of authentication accounts and employee records) ─────

function np_detach(array $w): void
{
    DB::table('employees')->whereIn('id', [$w['eHr']->id, $w['eSa']->id])->update(['user_id' => null]);
}

test('no employee profile: HR and System Super Admin keep organization MANAGEMENT through their explicit permissions; nothing is added', function () {
    $w = lc_world();
    np_detach($w);
    foreach (['hrT' => 'NP', 'saT' => 'NS'] as $tk => $code) {
        $p = ed_pos($this, $w[$tk], "{$code}1");
        $q = ed_pos($this, $w[$tk], "{$code}2", ['parent_id' => $p]);
        $a = ed_assign($this, $w[$tk], $q, $w['eNone']->id, 'primary', ['starts_on' => ed(-1)])->json('data.id');
        lc_call($this, $w[$tk], 'POST', "/api/v1/organization/assignments/$a/end", ['ends_on' => ed(0), 'reason' => ED_R])->assertOk();
        lc_call($this, $w[$tk], 'POST', "/api/v1/organization/positions/$q/reparent", ['parent_id' => null, 'reason' => ED_R])->assertOk();
        lc_call($this, $w[$tk], 'GET', '/api/v1/organization/unassigned')->assertOk();
        ed_row($a)->forceFill(['status' => 'ended'])->save();
        DB::table('org_position_assignments')->delete();
        DB::table('org_reporting_history')->delete();
        DB::table('org_positions')->delete();
    }
    // HR still has no roles/permissions capability: no profile did not add any
    lc_call($this, $w['hrT'], 'GET', '/api/v1/access/roles')->assertStatus(403);
});

test('no employee profile: an ordinary account stays restricted on reads and writes; a Platform Super Admin gets no tenant access; tenants stay isolated', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    np_detach($w);
    [$plain, $plainT] = lc_user($w['c'], 'employee', ['email' => 'plain2@a.test']);
    [$super, $superT] = lc_user($w['c'], 'super', ['email' => 'platform@a.test']);
    foreach ([$plainT, $superT] as $t) {
        lc_call($this, $t, 'GET', '/api/v1/organization/chart')->assertStatus(403);
        lc_call($this, $t, 'GET', '/api/v1/organization/people-without-position')->assertStatus(403);
        lc_call($this, $t, 'POST', '/api/v1/organization/positions', ['code' => 'NOPE', 'title' => 'x', 'reason' => ED_R])->assertStatus(403);
    }
    // an HR account without a profile in tenant A sees only tenant A and cannot touch tenant B's records
    $pa = ed_pos($this, $w['hrT'], 'ISO-A');
    $pb = ed_pos($this, $b['hrT'], 'ISO-B');
    $chart = ed_chart($this, $w['hrT']);
    expect(collect($chart['positions'])->pluck('code')->all())->toBe(['ISO-A']);
    lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$pb")->assertStatus(404);
    ed_assign($this, $w['hrT'], $pb, $w['eNone']->id, 'primary', [], 404);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$pb/archive", ['reason' => ED_R])->assertStatus(404);
    // revoking the permission removes the capability at once (no profile fallback)
    DB::table('user_roles')->where('user_id', $w['hr']->id)->delete();
    PermissionResolver::flush();
    lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/chart')->assertStatus(403);
});

// ───── The scheduled command is not the only correctness mechanism: nothing below runs it ─────

test('F01: with the expiry command never run, reads, vacancy, person and employee sections, assignment and placement are all correct', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'NC1');
    $p2 = ed_pos($this, $w['hrT'], 'NC2');
    $e = $w['eNone'];
    $old = ed_expired_placement($w, $p1, $e->id); // expired, still status=active, flag=true, command not run
    // vacancy and reads ignore it
    $n = ed_node(ed_chart($this, $w['hrT']), $p1);
    expect($n['vacant'])->toBeTrue()->and($n['primary_id'])->toBeNull();
    $people = ed_chart($this, $w['hrT'])['people'];
    expect($people)->not->toHaveKey((string) $e->id);
    $ids = collect(ed_chart($this, $w['empT'])['unassigned_groups'])->flatMap(fn ($g) => collect($g['people'])->pluck('id'))->all();
    expect($ids)->toContain($e->id);
    expect(lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/people/{$e->id}")->assertOk()->json('data.responsibilities'))->toBe([]);
    // still stored as active/flagged (the command did not run) ...
    expect(ed_row($old)->status)->toBe('active');
    // ... yet a replacement can be assigned to the same position by someone else, and the employee gets a new placement
    ed_assign($this, $w['hrT'], $p1, $w['eEmp']->id, 'primary', ['starts_on' => ed(0)]);
    $r = ed_assign($this, $w['hrT'], $p2, $e->id, 'primary', ['starts_on' => ed(0)]);
    expect($r->json('data.is_primary_placement'))->toBeTrue();
    expect(ed_row($old)->status)->toBe('ended'); // released by the write path itself, history kept
});

test('F01: setPlacement and end on an expired-but-still-active row are decided on the fresh state without the command', function () {
    $w = lc_world();
    $p1 = ed_pos($this, $w['hrT'], 'NE1');
    $p2 = ed_pos($this, $w['hrT'], 'NE2');
    $e = $w['eNone'];
    $old = ed_expired_placement($w, $p1, $e->id);
    $cur = ed_assign($this, $w['hrT'], $p2, $e->id, 'primary', ['starts_on' => ed(-1)])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$old/end", ['reason' => ED_R])->assertStatus(409); // already over: not active any more
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$cur/placement", ['reason' => ED_R])->assertOk();
    expect((bool) ed_row($cur)->is_primary_placement)->toBeTrue()->and((bool) ed_row($old)->is_primary_placement)->toBeFalse();
});

// ───── F05 (Option A approved): acting and deputy keep their own independent rules; a replacement can be scheduled in advance ─────

test('F05 Option A: replacement can be scheduled in advance from the earliest date; acting and deputy are not blocked by the closed primary window; nothing is restored or auto-appointed', function () {
    $w = lc_world();
    $p = ed_pos($this, $w['hrT'], 'OA1');
    $e = $w['eEmp'];
    $r1 = $w['eNone'];
    ed_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => ed(-30)]);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason', 'termination_date' => ed(10)])->assertOk();
    $n = ed_node(ed_chart($this, $w['hrT']), $p);
    expect($n['vacant'])->toBeTrue()->and($n['available_from'])->toBe(ed(11));
    // a replacement scheduled in advance (starts the day after) is accepted and is not effective yet: the chart still shows the seat as not available
    $adv = ed_assign($this, $w['hrT'], $p, $r1->id, 'primary', ['starts_on' => ed(11)])->json('data');
    expect($adv['scheduled'] ?? true)->toBeTrue();
    expect(ed_node(ed_chart($this, $w['hrT']), $p)['primary_id'])->toBeNull();
    // the day before the earliest date is refused with the date; the same-day overlap rule is unchanged
    ed_assign($this, $w['hrT'], $p, $w['eHr2']->id, 'primary', ['starts_on' => ed(10)], 409);
    // acting and deputy follow their own rules: not blocked by the closed primary window
    $ac = ed_assign($this, $w['hrT'], $p, $w['eHr2']->id, 'acting', ['starts_on' => ed(0)])->json('data.id');
    ed_assign($this, $w['hrT'], $p, $w['eSa']->id, 'deputy', ['starts_on' => ed(0)]);
    $n = ed_node(ed_chart($this, $w['hrT']), $p);
    expect($n['acting_id'])->toBe($w['eHr2']->id)->and($n['vacant'])->toBeTrue()->and($n['effective_holder_id'])->toBe($w['eHr2']->id);
    // a second acting overlapping the first is still refused
    ed_assign($this, $w['hrT'], $p, $w['eNone']->id, 'acting', ['starts_on' => ed(1)], 409);
    // reactivation restores nothing and appoints nobody
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/reactivate', ['reason' => 'Synthetic reactivation reason'])->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('employee_id', $e->id)->where('status', 'active')->count())->toBe(0);
});
