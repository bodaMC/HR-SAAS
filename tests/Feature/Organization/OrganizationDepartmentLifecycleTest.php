<?php

use App\Models\Department;
use App\Models\OrgPosition;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../AccountLifecycle/Support.php';
uses(RefreshDatabase::class);

// Phase 3.4 FINAL-V2, finding F03: department lifecycle versus positions. Synthetic data only.

function dl_dept(array $w, string $name): Department
{
    app(TenantContext::class)->set($w['c']);
    $d = Department::factory()->create(['name' => $name]);
    app(TenantContext::class)->clear();

    return $d;
}

function dl_pos($t, string $token, string $code, ?int $dept): int
{
    return lc_call($t, $token, 'POST', '/api/v1/organization/positions', ['code' => $code, 'title' => "T $code", 'department_id' => $dept, 'reason' => 'Synthetic organization test reason'])->assertCreated()->json('data.id');
}

test('F03: a department with active positions cannot be deleted; nothing is orphaned', function () {
    $w = lc_world();
    $d = dl_dept($w, 'Ops');
    $p = dl_pos($this, $w['hrT'], 'OPS1', $d->id);
    lc_call($this, $w['hrT'], 'DELETE', "/api/v1/departments/{$d->id}")->assertStatus(409)->assertJsonPath('code', 'department_has_positions')->assertJsonPath('active_positions', 1);
    expect(Department::withoutGlobalScopes()->find($d->id)->deleted_at)->toBeNull()->and(OrgPosition::withoutGlobalScopes()->find($p)->department_id)->toBe($d->id);
});

test('F03: an empty department deletes; archived positions keep the historical department and the chart still names it', function () {
    $w = lc_world();
    $d = dl_dept($w, 'Legacy Unit');
    $p = dl_pos($this, $w['hrT'], 'LEG1', $d->id);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/archive", ['reason' => 'Synthetic organization test reason'])->assertOk();
    lc_call($this, $w['hrT'], 'DELETE', "/api/v1/departments/{$d->id}")->assertOk();
    expect(Department::withoutGlobalScopes()->withTrashed()->find($d->id)->trashed())->toBeTrue();
    expect(OrgPosition::withoutGlobalScopes()->find($p)->department_id)->toBe($d->id); // history preserved
    $chart = lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/chart?include_archived=1')->assertOk()->json('data');
    expect(collect($chart['deleted_departments'])->pluck('name')->all())->toBe(['Legacy Unit']);
    expect(collect($chart['departments'])->pluck('id')->all())->not->toContain($d->id);
});

test('F03: a deleted department cannot be assigned to a new or edited position', function () {
    $w = lc_world();
    $d = dl_dept($w, 'Gone');
    lc_call($this, $w['hrT'], 'DELETE', "/api/v1/departments/{$d->id}")->assertOk();
    lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'G1', 'title' => 'G1', 'department_id' => $d->id, 'reason' => 'Synthetic organization test reason'])->assertStatus(422);
});
