<?php

// FINAL-V3 R4 hotfix: position removal marks the audit with self_release=true only when the acting administrator releases their OWN assignment (F20). Synthetic data only.

use App\Models\OrgPositionAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../AccountLifecycle/Support.php';

const SR_R = 'Synthetic self release reason';
const SR_BASE = '/api/v1/organization';

function sr_pos($t, string $token, string $code): int
{
    return lc_call($t, $token, 'POST', SR_BASE.'/positions', ['code' => $code, 'title' => 'Same Title'])->assertStatus(201)->json('data.id');
}

function sr_assign($t, string $token, int $pos, int $emp, array $x = [], int $st = 201): ?int
{
    return lc_call($t, $token, 'POST', SR_BASE."/positions/$pos/assignments", $x + ['employee_id' => $emp, 'type' => 'deputy'])->assertStatus($st)->json('data.id');
}

/** audit rows of an action as decoded new_values */
function sr_rows(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->map(fn ($r) => json_decode((string) $r->new_values, true) ?: [])->all();
}

function sr_marked(array $rows): int
{
    return count(array_filter($rows, fn ($v) => ($v['self_release'] ?? false) === true));
}

test('removal with End: the administrator ending their OWN assignment is audited with self_release=true, someone else\'s assignment in the same removal is not', function () {
    $w = lc_world();
    $p = sr_pos($this, $w['hrT'], 'SR-END');
    $mine = sr_assign($this, $w['hr2T'], $p, $w['eHr']->id); // the profile of hrT, assigned by the other administrator
    $other = sr_assign($this, $w['hr2T'], $p, $w['eNone']->id);
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p/delete", ['confirm' => true, 'reason' => SR_R, 'resolutions' => [
        ['assignment_id' => $mine, 'action' => 'end'], ['assignment_id' => $other, 'action' => 'end'],
    ]])->assertOk()->assertJsonPath('data.mode', 'removed');
    $ended = sr_rows('org.assignment.ended');
    expect($ended)->toHaveCount(2)->and(sr_marked($ended))->toBe(1);
    $mark = collect($ended)->first(fn ($v) => ($v['self_release'] ?? false) === true);
    expect($mark['cause'])->toBe('position_removed');
    // end cause and status unchanged by the fix
    expect(OrgPositionAssignment::withoutGlobalScopes()->whereKey($mine)->value('end_cause'))->toBe('position_removed')->and(OrgPositionAssignment::withoutGlobalScopes()->whereKey($other)->value('end_cause'))->toBe('position_removed');
    expect(DB::table('org_positions')->where('id', $p)->value('status'))->toBe('removed');
});

test('removal with End on a scheduled (future) own assignment is also marked', function () {
    $w = lc_world();
    $p = sr_pos($this, $w['hrT'], 'SR-FUT');
    $mine = sr_assign($this, $w['hr2T'], $p, $w['eHr']->id, ['starts_on' => now()->addDays(5)->toDateString()]);
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $mine, 'action' => 'end']]])->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->whereKey($mine)->value('status'))->toBe('cancelled');
    expect(sr_marked(sr_rows('org.assignment.ended')))->toBe(1);
});

test('removal with Move: closing the old assignment is marked only when it is the actor\'s own; self placement stays refused and everything rolls back', function () {
    $w = lc_world();
    $p = sr_pos($this, $w['hrT'], 'SR-MV');
    $q = sr_pos($this, $w['hrT'], 'SR-MV2');
    $mine = sr_assign($this, $w['hr2T'], $p, $w['eHr']->id);
    $other = sr_assign($this, $w['hr2T'], $p, $w['eNone']->id);
    // the other administrator moves the first administrator's assignment: not the actor's own, so no mark
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hr2T'], 'POST', SR_BASE."/positions/$p/delete", ['confirm' => true, 'resolutions' => [
        ['assignment_id' => $mine, 'action' => 'move', 'target_position_id' => $q], ['assignment_id' => $other, 'action' => 'move', 'target_position_id' => $q],
    ]])->assertOk()->assertJsonPath('data.summary.moved', 2);
    $ended = sr_rows('org.assignment.ended');
    expect($ended)->toHaveCount(2)->and(sr_marked($ended))->toBe(0);

    // own assignment moved by its owner: the existing self-target rule refuses the new self placement, the whole removal rolls back, no audit row remains
    $p3 = sr_pos($this, $w['hrT'], 'SR-MV3');
    $q3 = sr_pos($this, $w['hrT'], 'SR-MV4');
    $own = sr_assign($this, $w['hr2T'], $p3, $w['eHr']->id);
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p3/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $own, 'action' => 'move', 'target_position_id' => $q3]]])->assertStatus(403);
    expect(OrgPositionAssignment::withoutGlobalScopes()->whereKey($own)->value('status'))->toBe('active')->and(DB::table('org_positions')->where('id', $p3)->value('status'))->toBe('active')
        ->and(DB::table('audit_logs')->count())->toBe(0);
});

test('removal with Move of someone else\'s assignment by an administrator who also holds a seat is not marked, and void stays marked only for own', function () {
    $w = lc_world();
    $p = sr_pos($this, $w['hrT'], 'SR-OTH');
    $q = sr_pos($this, $w['hrT'], 'SR-OTH2');
    $mine = sr_assign($this, $w['hr2T'], sr_pos($this, $w['hrT'], 'SR-HELD'), $w['eHr']->id); // hrT holds a seat elsewhere
    $other = sr_assign($this, $w['hrT'], $p, $w['eNone']->id);
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $other, 'action' => 'move', 'target_position_id' => $q]]])->assertOk();
    expect(sr_marked(sr_rows('org.assignment.ended')))->toBe(0)->and(sr_marked(sr_rows('org.assignment.created')))->toBe(0);
    // void of own assignment through removal (existing behaviour) stays marked; void of someone else's does not
    $p5 = sr_pos($this, $w['hrT'], 'SR-VD');
    $own = sr_assign($this, $w['hr2T'], $p5, $w['eHr']->id);
    $oth = sr_assign($this, $w['hr2T'], $p5, $w['eNone']->id);
    DB::table('audit_logs')->delete();
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p5/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $own, 'action' => 'void'], ['assignment_id' => $oth, 'action' => 'void']]])->assertOk();
    $v = sr_rows('org.assignment.voided');
    expect($v)->toHaveCount(2)->and(sr_marked($v))->toBe(1);
    expect($mine)->toBeInt();
});

test('unrelated assignment operations are not marked: ordinary end, ordinary assign and a removal with no own assignment', function () {
    $w = lc_world();
    $p = sr_pos($this, $w['hrT'], 'SR-UNR');
    DB::table('audit_logs')->delete();
    $a = sr_assign($this, $w['hrT'], $p, $w['eNone']->id);
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/assignments/$a/end", [])->assertOk();
    $b = sr_assign($this, $w['hrT'], $p, $w['eHr2']->id);
    lc_call($this, $w['hrT'], 'POST', SR_BASE."/positions/$p/delete", ['confirm' => true, 'resolutions' => [['assignment_id' => $b, 'action' => 'end']]])->assertOk();
    foreach (['org.assignment.created', 'org.assignment.ended', 'org.position.removed'] as $act) {
        expect(sr_marked(sr_rows($act)))->toBe(0);
    }
    expect(sr_rows('org.assignment.ended'))->toHaveCount(2);
});
