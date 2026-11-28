<?php

// FINAL-V3: configurable occupancy (single incumbent / multiple incumbents with optional capacity).

use App\Models\OrgPositionAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../AccountLifecycle/Support.php';

const OC_R = 'Synthetic occupancy reason';

function oc_day(int $n): string
{
    return Carbon::now(config('app.timezone'))->addDays($n)->toDateString();
}

function oc_pos($t, string $token, string $code, array $x = [], int $st = 201)
{
    return lc_call($t, $token, 'POST', '/api/v1/organization/positions', $x + ['code' => $code, 'title' => "T $code", 'reason' => OC_R])->assertStatus($st);
}

function oc_as($t, string $token, int $pos, int $emp, array $x = [], int $st = 201)
{
    return lc_call($t, $token, 'POST', "/api/v1/organization/positions/$pos/assignments", $x + ['employee_id' => $emp, 'type' => 'primary', 'reason' => OC_R])->assertStatus($st);
}

function oc_emps($c, int $n): array
{
    return collect(range(1, $n))->map(fn ($i) => lc_emp($c, ['email' => "oc$i.".uniqid().'@e.test']))->all();
}

test('single incumbent is the default and keeps one primary; multiple allows several different employees but never the same one twice', function () {
    $w = lc_world();
    [$e1, $e2, $e3] = oc_emps($w['c'], 3);
    $ceo = oc_pos($this, $w['hrT'], 'CEO')->assertJsonPath('data.occupancy_type', 'single')->assertJsonPath('data.capacity', null)->json('data.id');
    oc_as($this, $w['hrT'], $ceo, $e1->id);
    oc_as($this, $w['hrT'], $ceo, $e2->id, [], 409)->assertJsonPath('code', 'primary_conflict');
    $dc = oc_pos($this, $w['hrT'], 'DC', ['occupancy_type' => 'multiple'])->assertJsonPath('data.occupancy_type', 'multiple')->json('data.id');
    oc_as($this, $w['hrT'], $dc, $e1->id);
    oc_as($this, $w['hrT'], $dc, $e2->id);
    oc_as($this, $w['hrT'], $dc, $e3->id);
    oc_as($this, $w['hrT'], $dc, $e1->id, [], 409)->assertJsonPath('code', 'duplicate_assignment');
    $chart = lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/chart')->assertOk()->json('data.positions');
    $n = collect($chart)->firstWhere('id', $dc);
    expect($n['headcount'])->toBe(3)->and($n['primary_ids'])->toHaveCount(3)->and($n['vacant'])->toBeFalse()->and($n['occupancy_type'])->toBe('multiple');
    expect(collect($chart)->firstWhere('id', $ceo)['headcount'])->toBe(1);
});

test('capacity counts every day of the window: limited and unlimited, future windows, and ending one employee leaves the others untouched', function () {
    $w = lc_world();
    [$a, $b, $c, $d] = oc_emps($w['c'], 4);
    $p = oc_pos($this, $w['hrT'], 'ENG', ['occupancy_type' => 'multiple', 'capacity' => 2])->assertJsonPath('data.capacity', 2)->json('data.id');
    $ia = oc_as($this, $w['hrT'], $p, $a->id)->json('data.id');
    oc_as($this, $w['hrT'], $p, $b->id);
    oc_as($this, $w['hrT'], $p, $c->id, [], 409)->assertJsonPath('code', 'capacity_full')->assertJsonPath('capacity', 2);
    // a future window that starts after one of them ends fits; one that overlaps both does not
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/end", ['reason' => OC_R, 'ends_on' => oc_day(3)])->assertOk();
    oc_as($this, $w['hrT'], $p, $c->id, ['starts_on' => oc_day(4)]);
    oc_as($this, $w['hrT'], $p, $d->id, ['starts_on' => oc_day(2), 'ends_on' => oc_day(5)], 409)->assertJsonPath('code', 'capacity_full');
    // removing one does not affect the others
    $chart = fn () => collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/chart')->json('data.positions'))->firstWhere('id', $p);
    expect($chart()['headcount'])->toBe(2);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/void", ['reason' => OC_R, 'confirm' => true])->assertOk();
    expect($chart()['primary_ids'])->toBe([$b->id]);
    oc_as($this, $w['hrT'], $p, $d->id, ['ends_on' => oc_day(3)]); // b + d today; c starts day 4: never above 2
    oc_as($this, $w['hrT'], $p, oc_emps($w['c'], 1)[0]->id, ['ends_on' => oc_day(3)], 409)->assertJsonPath('code', 'capacity_full');
    // unlimited
    $u = oc_pos($this, $w['hrT'], 'UNL', ['occupancy_type' => 'multiple'])->json('data.id');
    foreach (oc_emps($w['c'], 5) as $e) {
        oc_as($this, $w['hrT'], $u, $e->id);
    }
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $u)->count())->toBe(5);
});

test('individual replace, correct and reinstate work per employee on a multiple position and respect capacity', function () {
    $w = lc_world();
    [$a, $b, $c] = oc_emps($w['c'], 3);
    $p = oc_pos($this, $w['hrT'], 'SHARED', ['occupancy_type' => 'multiple', 'capacity' => 2])->json('data.id');
    $ia = oc_as($this, $w['hrT'], $p, $a->id)->json('data.id');
    $ib = oc_as($this, $w['hrT'], $p, $b->id)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/replace", ['employee_id' => $c->id, 'mode' => 'correction', 'reason' => OC_R, 'confirm' => true])->assertStatus(201);
    expect(OrgPositionAssignment::withoutGlobalScopes()->find($ib)->status)->toBe('active');
    $chart = collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/chart')->json('data.positions'))->firstWhere('id', $p);
    expect($chart['primary_ids'])->toEqualCanonicalizing([$b->id, $c->id]);
    // reinstating the voided one is refused while the position is full
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/reinstate", ['reason' => OC_R, 'confirm' => true])->assertStatus(409)->assertJsonPath('code', 'capacity_full');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ib/end", ['reason' => OC_R])->assertOk();
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/reinstate", ['reason' => OC_R, 'confirm' => true])->assertStatus(409); // inclusive dates: b still holds a place today (Option A)
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ib/reinstate", ['reason' => OC_R, 'confirm' => true])->assertOk();
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ib/void", ['reason' => OC_R, 'confirm' => true])->assertOk();
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ia/reinstate", ['reason' => OC_R, 'confirm' => true])->assertOk();
});

test('occupancy changes are validated against current and future assignments; multiple to single is blocked while several primaries exist', function () {
    $w = lc_world();
    [$a, $b, $c] = oc_emps($w['c'], 3);
    $p = oc_pos($this, $w['hrT'], 'CHG', ['occupancy_type' => 'multiple'])->json('data.id');
    $ia = oc_as($this, $w['hrT'], $p, $a->id)->json('data.id');
    oc_as($this, $w['hrT'], $p, $b->id, ['starts_on' => oc_day(5)]);
    $put = fn ($x) => lc_call($this, $w['hrT'], 'PUT', "/api/v1/organization/positions/$p", $x + ['reason' => OC_R]);
    $put(['occupancy_type' => 'single'])->assertStatus(409)->assertJsonPath('code', 'occupancy_incompatible');
    $put(['capacity' => 1])->assertStatus(409)->assertJsonPath('code', 'capacity_below_occupancy')->assertJsonPath('peak', 2);
    $put(['capacity' => 2])->assertOk()->assertJsonPath('data.capacity', 2);
    $ib = OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p)->where('employee_id', $b->id)->value('id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$ib/void", ['reason' => OC_R, 'confirm' => true])->assertOk();
    $put(['occupancy_type' => 'single'])->assertOk()->assertJsonPath('data.occupancy_type', 'single')->assertJsonPath('data.capacity', null);
    oc_as($this, $w['hrT'], $p, $c->id, [], 409)->assertJsonPath('code', 'primary_conflict'); // single rules apply again
    expect(DB::table('audit_logs')->where('action', 'org.position.updated')->count())->toBeGreaterThanOrEqual(2);
    lc_call($this, $w['empT'], 'PUT', "/api/v1/organization/positions/$p", ['occupancy_type' => 'multiple', 'reason' => OC_R])->assertStatus(403);
});
