<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../AccountLifecycle/Support.php';
uses(RefreshDatabase::class);

// Phase 3.4 FINAL-V2, finding F02: tenant isolation enforced by the DATABASE, bypassing every model scope and service. Synthetic data only.
// Each deliberately failing statement runs inside a savepoint so the PostgreSQL transaction stays usable.

function tc_rejects(callable $fn): bool
{
    DB::beginTransaction();
    try {
        $fn();
        DB::rollBack();

        return false;
    } catch (QueryException) {
        DB::rollBack();

        return true;
    }
}

function tc_dept(int $company, string $name): int
{
    return DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

function tc_position(int $company, string $code, array $x = []): int
{
    return DB::table('org_positions')->insertGetId($x + ['uuid' => (string) Str::uuid(), 'company_id' => $company, 'code' => $code, 'title' => $code, 'kind' => 'position', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

function tc_assignment(int $company, int $pos, int $emp, array $x = []): int
{
    return DB::table('org_position_assignments')->insertGetId($x + ['uuid' => (string) Str::uuid(), 'company_id' => $company, 'position_id' => $pos, 'employee_id' => $emp, 'type' => 'primary', 'status' => 'active',
        'starts_on' => '2026-01-01', 'is_primary_placement' => false, 'created_at' => now(), 'updated_at' => now()]);
}

test('F02: the database rejects an assignment whose employee belongs to another tenant', function () {
    $a = lc_world();
    $b = lc_world('b.test');
    $posA = tc_position($a['c']->id, 'A1');
    expect(tc_assignment($a['c']->id, $posA, $a['eNone']->id))->toBeInt(); // same tenant is fine
    expect(tc_rejects(fn () => tc_assignment($a['c']->id, $posA, $b['eNone']->id)))->toBeTrue();
});

test('F02: the database rejects an assignment whose position belongs to another tenant', function () {
    $a = lc_world();
    $b = lc_world('b.test');
    $posB = tc_position($b['c']->id, 'B1');
    expect(tc_rejects(fn () => tc_assignment($a['c']->id, $posB, $a['eNone']->id)))->toBeTrue();
});

test('F02: the database rejects a position whose department belongs to another tenant', function () {
    $a = lc_world();
    $b = lc_world('b.test');
    $deptA = tc_dept($a['c']->id, 'Dept A');
    $deptB = tc_dept($b['c']->id, 'Dept B');
    expect(tc_position($a['c']->id, 'D1', ['department_id' => $deptA]))->toBeInt();
    expect(tc_rejects(fn () => tc_position($a['c']->id, 'D2', ['department_id' => $deptB])))->toBeTrue();
});

test('F02: the database rejects a position whose parent belongs to another tenant', function () {
    $a = lc_world();
    $b = lc_world('b.test');
    $posB = tc_position($b['c']->id, 'PB');
    expect(tc_rejects(fn () => tc_position($a['c']->id, 'PA', ['parent_id' => $posB])))->toBeTrue();
});

test('F02: the database rejects reporting-history parent references from another tenant', function () {
    $a = lc_world();
    $b = lc_world('b.test');
    $posA = tc_position($a['c']->id, 'RA');
    $posA2 = tc_position($a['c']->id, 'RA2');
    $posB = tc_position($b['c']->id, 'RB');
    $row = fn (array $x) => DB::table('org_reporting_history')->insert($x + ['company_id' => $a['c']->id, 'position_id' => $posA, 'from_parent_id' => null, 'to_parent_id' => null, 'changed_at' => now()]);
    expect(tc_rejects(fn () => $row(['from_parent_id' => $posB])))->toBeTrue();
    expect(tc_rejects(fn () => $row(['to_parent_id' => $posB])))->toBeTrue();
    expect(tc_rejects(fn () => $row(['position_id' => $posB])))->toBeTrue();
    expect(tc_rejects(fn () => $row(['from_parent_id' => $posA2, 'to_parent_id' => $posA2])))->toBeFalse(); // same tenant is accepted
});

test('F02: a department with positions cannot be hard-deleted at the database level', function () {
    $a = lc_world();
    $d = tc_dept($a['c']->id, 'Dept X');
    tc_position($a['c']->id, 'X1', ['department_id' => $d]);
    expect(tc_rejects(fn () => DB::table('departments')->where('id', $d)->delete()))->toBeTrue();
});

test('F01 (PostgreSQL): the database rejects two overlapping primary placements for one employee and accepts consecutive ones', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The exclusion constraint is PostgreSQL only; SQLite relies on the service rule (covered by the feature tests).');
    }
    $a = lc_world();
    $p1 = tc_position($a['c']->id, 'G1');
    $p2 = tc_position($a['c']->id, 'G2');
    $e = $a['eNone']->id;
    tc_assignment($a['c']->id, $p1, $e, ['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'is_primary_placement' => true]);
    expect(tc_rejects(fn () => tc_assignment($a['c']->id, $p2, $e, ['starts_on' => '2026-03-31', 'is_primary_placement' => true])))->toBeTrue(); // inclusive end overlaps
    expect(tc_rejects(fn () => tc_assignment($a['c']->id, $p2, $e, ['starts_on' => '2026-04-01', 'is_primary_placement' => true])))->toBeFalse(); // consecutive
    expect(tc_rejects(fn () => tc_assignment($a['c']->id, $p2, $e, ['starts_on' => '2026-03-01', 'is_primary_placement' => false])))->toBeFalse(); // not a placement
});
