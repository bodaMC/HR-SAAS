<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OrgPosition;
use App\Models\OrgPositionAssignment;
use App\Models\User;
use App\Organization\OrgService;
use App\Rbac\PermissionResolver;
use App\Rbac\RoleReconciler;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../AccountLifecycle/Support.php';
uses(RefreshDatabase::class);

const OR_R = 'Synthetic organization test reason';

function or_pos($t, string $token, string $code, array $extra = [], int $status = 201): ?int
{
    $res = lc_call($t, $token, 'POST', '/api/v1/organization/positions', ['code' => $code, 'title' => "Title $code", 'reason' => OR_R] + $extra);
    $res->assertStatus($status);

    return $status === 201 ? $res->json('data.id') : null;
}

function or_assign($t, string $token, int $pos, int $emp, string $type = 'primary', array $extra = [], int $status = 201)
{
    $res = lc_call($t, $token, 'POST', "/api/v1/organization/positions/$pos/assignments", ['employee_id' => $emp, 'type' => $type, 'reason' => OR_R] + $extra);
    $res->assertStatus($status);

    return $res;
}

function or_chart($t, string $token): array
{
    return lc_call($t, $token, 'GET', '/api/v1/organization/chart')->assertOk()->json('data');
}

function or_node(array $chart, int $id): array
{
    return collect($chart['positions'])->firstWhere('id', $id);
}

test('HR and SSA hold the organization permissions; HR still has no roles or permissions management', function () {
    $w = lc_world();
    foreach (['hr', 'sa'] as $k) {
        foreach (['organization.positions.manage', 'organization.assignments.manage', 'organization.reporting.manage', 'organization.history.view'] as $p) {
            expect(PermissionResolver::has($w[$k], $p))->toBeTrue("$k $p");
        }
    }
    foreach (['roles.view', 'roles.assign', 'permissions.manage', 'permissions.view'] as $p) {
        expect(PermissionResolver::has($w['hr'], $p))->toBeFalse();
    }
    expect(PermissionResolver::has($w['emp'], 'organization.positions.manage'))->toBeFalse();
});

test('position CRUD: independent of incumbents, unique code per tenant, roots, governing bodies, validation and archive', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    $board = or_pos($this, $w['hrT'], 'BOARD', ['kind' => 'body', 'title_en' => 'Board of Directors', 'title_ar' => 'مجلس الإدارة']);
    $ceo = or_pos($this, $w['hrT'], 'CEO', ['parent_id' => $board]);
    or_pos($this, $w['hrT'], 'CEO', [], 409); // duplicate code in the tenant
    $otherTenant = or_pos($this, $b['hrT'], 'CEO'); // the same code is fine in another tenant
    expect($otherTenant)->not->toBeNull();
    // parent from another tenant is indistinguishable from a missing one
    or_pos($this, $w['hrT'], 'X1', ['parent_id' => $otherTenant], 422);
    or_pos($this, $w['hrT'], 'X2', ['parent_id' => 999999], 422);
    // validation: reason optional (UAT-05) but capped at 500 characters, code charset
    lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'Z', 'title' => 'Z', 'reason' => str_repeat('x', 501)])->assertStatus(422);
    lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'Z', 'title' => 'Z'])->assertStatus(201);
    expect(DB::table('audit_logs')->where('action', 'org.position.created')->latest('id')->value('reason'))->toBeNull();
    lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'bad code!', 'title' => 'Z', 'reason' => OR_R])->assertStatus(422);
    // two Team Leader positions in one department are distinct positions
    $dept = DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'name' => 'Architecture', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $tl1 = or_pos($this, $w['hrT'], 'TL-ARCH-1', ['department_id' => $dept, 'parent_id' => $ceo]);
    $tl2 = or_pos($this, $w['hrT'], 'TL-ARCH-2', ['department_id' => $dept, 'parent_id' => $ceo]);
    expect($tl1)->not->toBe($tl2);
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/organization/positions/$tl1", ['title' => 'Team Leader A', 'reason' => OR_R])->assertOk()->assertJsonPath('data.title', 'Team Leader A');
    // reporting can only change through reparent
    lc_call($this, $w['hrT'], 'PUT', "/api/v1/organization/positions/$tl1", ['parent_id' => $board, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'use_reparent');
    // a governing body has no individual incumbent
    or_assign($this, $w['hrT'], $board, $w['eEmp']->id, 'primary', [], 422);
    // department of another tenant
    $bd = DB::table('departments')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $b['c']->id, 'name' => 'B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    or_pos($this, $w['hrT'], 'X3', ['department_id' => $bd], 422);
    // cross-tenant read / write is a 404
    lc_call($this, $b['hrT'], 'GET', "/api/v1/organization/positions/$ceo")->assertStatus(404);
    lc_call($this, $b['hrT'], 'PUT', "/api/v1/organization/positions/$ceo", ['title' => 'Hacked', 'reason' => OR_R])->assertStatus(404);
    expect(OrgPosition::withoutGlobalScopes()->find($ceo)->title)->toBe('Title CEO');
    // list filters
    $list = lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/positions?search=TL-ARCH&department_id='.$dept)->assertOk();
    expect($list->json('meta.total'))->toBe(2);
    expect(collect(lc_call($this, $w['hrT'], 'GET', '/api/v1/organization/positions?occupancy=vacant')->json('data'))->pluck('code')->all())->toContain('CEO', 'TL-ARCH-1')->not->toContain('BOARD');
});

test('assignment semantics: primary, acting and deputy; conflicts; multiple positions; vacancy with acting; precedence; future dates', function () {
    $w = lc_world();
    $eng = or_pos($this, $w['hrT'], 'ENG-MGR');
    $ceo = or_pos($this, $w['hrT'], 'CEO');
    $tl = or_pos($this, $w['hrT'], 'TL-1', ['parent_id' => $eng]);
    $ceoE = $w['eEmp'];
    $other = $w['eNone'];
    // CEO holds the CEO position and is ACTING Engineering Manager; ENG-MGR primary stays vacant
    or_assign($this, $w['hrT'], $ceo, $ceoE->id, 'primary');
    $a = or_assign($this, $w['hrT'], $eng, $ceoE->id, 'acting')->json('data.id');
    $chart = or_chart($this, $w['empT']);
    $n = or_node($chart, $eng);
    expect($n['vacant'])->toBeTrue()->and($n['primary_id'])->toBeNull()->and($n['acting_id'])->toBe($ceoE->id)->and($n['effective_holder_id'])->toBe($ceoE->id);
    expect(or_node($chart, $ceo)['primary_id'])->toBe($ceoE->id); // the acting employee keeps the original position
    expect($chart['people'][$ceoE->id]['position_count'])->toBe(2);
    // conflicts
    or_assign($this, $w['hrT'], $eng, $other->id, 'acting', [], 409);            // second acting
    or_assign($this, $w['hrT'], $eng, $ceoE->id, 'deputy', [], 409);             // same employee twice on the position
    or_assign($this, $w['hrT'], $ceo, $other->id, 'primary', [], 409);           // second primary
    // a deputy is not an acting incumbent and never the effective holder
    or_assign($this, $w['hrT'], $eng, $other->id, 'deputy');
    $n = or_node(or_chart($this, $w['empT']), $eng);
    expect($n['deputy_ids'])->toBe([$other->id])->and($n['effective_holder_id'])->toBe($ceoE->id)->and($n['vacant'])->toBeTrue();
    // a real primary appears: still vacant=false, effective holder = acting (precedence acting > primary), then acting ends
    $e3 = lc_emp($w['c'], ['email' => 'p@a.test']);
    or_assign($this, $w['hrT'], $eng, $e3->id, 'primary');
    $n = or_node(or_chart($this, $w['empT']), $eng);
    expect($n['vacant'])->toBeFalse()->and($n['effective_holder_id'])->toBe($ceoE->id);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$a/end", ['reason' => OR_R])->assertOk()->assertJsonPath('data.status', 'ended');
    expect(or_node(or_chart($this, $w['empT']), $eng)['effective_holder_id'])->toBe($e3->id);
    // dates: disjoint ranges for the same type are fine, overlapping are not; future-effective is not current
    or_assign($this, $w['hrT'], $tl, $e3->id, 'primary', ['starts_on' => '2099-01-01', 'ends_on' => '2099-06-30']);
    or_assign($this, $w['hrT'], $tl, $other->id, 'primary', ['starts_on' => '2099-07-01']);
    or_assign($this, $w['hrT'], $tl, $ceoE->id, 'primary', ['starts_on' => '2099-06-01'], 409);
    or_assign($this, $w['hrT'], $tl, $ceoE->id, 'deputy', ['starts_on' => '2099-05-01', 'ends_on' => '2099-04-01'], 422);
    expect(or_node(or_chart($this, $w['empT']), $tl)['vacant'])->toBeTrue(); // nothing effective today
    $rows = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$tl")->assertOk()->json('data.assignments');
    expect(collect($rows)->where('scheduled', true))->toHaveCount(2);
    // each employee has an explicit single placement
    expect(OrgPositionAssignment::withoutGlobalScopes()->where('employee_id', $ceoE->id)->where('is_primary_placement', true)->count())->toBe(1);
});

test('primary placement is explicit: sole assignment auto-placed, later ones are not, changing it is a separate action', function () {
    $w = lc_world();
    $p1 = or_pos($this, $w['hrT'], 'P1');
    $p2 = or_pos($this, $w['hrT'], 'P2');
    $p3 = or_pos($this, $w['hrT'], 'P3');
    $e = $w['eNone'];
    $a1 = or_assign($this, $w['hrT'], $p1, $e->id)->json('data');
    $a2 = or_assign($this, $w['hrT'], $p2, $e->id)->json('data');
    expect($a1['is_primary_placement'])->toBeTrue()->and($a2['is_primary_placement'])->toBeFalse();
    or_assign($this, $w['hrT'], $p3, $e->id, 'primary', ['is_primary_placement' => true], 409);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/{$a2['id']}/placement", ['reason' => OR_R])->assertOk()->assertJsonPath('data.is_primary_placement', true);
    expect(OrgPositionAssignment::withoutGlobalScopes()->find($a1['id'])->is_primary_placement)->toBeFalse();
    // a deputy cannot be the placement
    $d = or_assign($this, $w['hrT'], $p3, $e->id, 'deputy')->json('data');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/{$d['id']}/placement", ['reason' => OR_R])->assertStatus(422);
});

test('reporting: self parent, cycles, archived and cross-tenant parents rejected; history recorded; stale expectation rejected', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    $a = or_pos($this, $w['hrT'], 'A');
    $bb = or_pos($this, $w['hrT'], 'B', ['parent_id' => $a]);
    $c = or_pos($this, $w['hrT'], 'C', ['parent_id' => $bb]);
    $url = fn ($id) => "/api/v1/organization/positions/$id/reparent";
    lc_call($this, $w['hrT'], 'POST', $url($a), ['parent_id' => $a, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'org_cycle');
    lc_call($this, $w['hrT'], 'POST', $url($a), ['parent_id' => $c, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'org_cycle');
    lc_call($this, $w['hrT'], 'POST', $url($a), ['parent_id' => $bb, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'org_cycle');
    $x = or_pos($this, $b['hrT'], 'X');
    lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $x, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'parent_invalid');
    lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $a, 'reason' => str_repeat('x', 501)])->assertStatus(422); // reason max length
    lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $bb, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'parent_unchanged');
    lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $a, 'expected_parent_id' => $a, 'reason' => OR_R])->assertStatus(409)->assertJsonPath('code', 'stale_parent');
    $ok = lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $a, 'expected_parent_id' => $bb, 'reason' => OR_R])->assertOk();
    expect($ok->json('branch_positions'))->toBe(1)->and(OrgPosition::withoutGlobalScopes()->find($c)->parent_id)->toBe($a);
    // moving to a legitimate root is explicit
    lc_call($this, $w['hrT'], 'POST', $url($bb), ['parent_id' => null, 'reason' => OR_R])->assertOk();
    // archived parent rejected; archiving a parent with active children rejected
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$bb/archive", ['reason' => OR_R])->assertOk();
    lc_call($this, $w['hrT'], 'POST', $url($c), ['parent_id' => $bb, 'reason' => OR_R])->assertStatus(422)->assertJsonPath('code', 'parent_archived');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$a/archive", ['reason' => OR_R])->assertStatus(409)->assertJsonPath('code', 'has_active_children');
    $h = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$c/history")->assertOk()->json('data.reporting');
    expect($h)->toHaveCount(2); // created under B, then moved to A
    // the audit trail has before/after and the reason
    $log = AuditLog::withoutGlobalScopes()->where('action', 'org.position.reparented')->latest('id')->first();
    expect($log->reason)->toBe(OR_R)->and($log->visibility)->toBe('operational');
});

test('archive ends live assignments, keeps history, excludes the position from the chart, and restore never restores assignments', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'P');
    $a = or_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    $res = lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/archive", ['reason' => OR_R])->assertOk();
    expect($res->json('assignments_ended'))->toBe(1);
    $row = OrgPositionAssignment::withoutGlobalScopes()->find($a);
    expect($row->status)->toBe('ended')->and($row->end_cause)->toBe('position_archived');
    expect(collect(or_chart($this, $w['empT'])['positions'])->pluck('id')->all())->not->toContain($p);
    or_assign($this, $w['hrT'], $p, $w['eNone']->id, 'primary', [], 409);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/restore", ['reason' => OR_R])->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->find($a)->status)->toBe('ended');
    expect(or_node(or_chart($this, $w['empT']), $p)['vacant'])->toBeTrue();
});

test('privacy: the chart and person panel expose no personal, security or financial data', function () {
    $w = lc_world();
    $e = lc_emp($w['c'], ['email' => 'secret.mail@a.test', 'phone' => '+201099988877', 'national_id' => '29901011234567', 'date_of_birth' => '1990-01-01']);
    $p = or_pos($this, $w['hrT'], 'P');
    or_assign($this, $w['hrT'], $p, $e->id);
    $chart = lc_call($this, $w['empT'], 'GET', '/api/v1/organization/chart')->assertOk();
    $person = lc_call($this, $w['empT'], 'GET', '/api/v1/organization/people/'.$e->id)->assertOk();
    foreach ([$chart->getContent(), $person->getContent()] as $json) {
        foreach (['secret.mail@a.test', '+201099988877', '29901011234567', '1990-01-01', 'national_id', 'date_of_birth', 'phone', 'salary', 'user_id', 'is_active', 'role', 'permission', 'password', 'email'] as $needle) {
            expect(str_contains($json, $needle))->toBeFalse("leaked $needle");
        }
    }
    expect(array_keys((array) $chart->json('data.people.'.$e->id)))->toBe(['id', 'name', 'job_title', 'department', 'has_picture', 'picture_version', 'position_count', 'placement_position_id']);
    // the ordinary employee never receives the administrative employee resource or the unassigned list
    expect($chart->json('data.can_manage'))->toBeFalse()->and($chart->json('data'))->not->toHaveKey('unassigned_count');
    lc_call($this, $w['empT'], 'GET', '/api/v1/organization/unassigned')->assertStatus(403);
    lc_call($this, $w['empT'], 'GET', '/api/v1/organization/positions')->assertStatus(403);
    lc_call($this, $w['empT'], 'GET', '/api/v1/employees/'.$e->id.'/organization')->assertStatus(403);
    // terminated employees are not reachable through the limited panel
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason'])->assertOk();
    lc_call($this, $w['empT'], 'GET', '/api/v1/organization/people/'.$e->id)->assertStatus(404);
});

test('authorization: employees read only; every mutation needs its own permission; platform admin and cross-tenant denied', function () {
    $w = lc_world();
    $b = lc_world('b.test');
    [$plat, $platT] = lc_user($w['c'], 'super', ['email' => 'platform@x.test']);
    $p = or_pos($this, $w['hrT'], 'P');
    $a = or_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    $muts = [
        ['POST', '/api/v1/organization/positions', ['code' => 'N', 'title' => 'N', 'reason' => OR_R]],
        ['PUT', "/api/v1/organization/positions/$p", ['title' => 'N', 'reason' => OR_R]],
        ['POST', "/api/v1/organization/positions/$p/archive", ['reason' => OR_R]],
        ['POST', "/api/v1/organization/positions/$p/reparent", ['parent_id' => null, 'reason' => OR_R]],
        ['POST', "/api/v1/organization/positions/$p/assignments", ['employee_id' => $w['eHr']->id, 'type' => 'deputy', 'reason' => OR_R]],
        ['POST', "/api/v1/organization/assignments/$a/end", ['reason' => OR_R]],
        ['POST', "/api/v1/organization/assignments/$a/placement", ['reason' => OR_R]],
    ];
    foreach ([$w['empT'], $platT, $b['hrT']] as $tok) {
        foreach ($muts as [$m, $u, $d]) {
            if ($tok === $b['hrT'] && $u === '/api/v1/organization/positions') {
                continue; // creating a position in its OWN tenant is legitimate for that tenant's HR
            }
            expect(lc_call($this, $tok, $m, $u, $d)->status())->toBeIn([403, 404], "$m $u");
        }
    }
    lc_call($this, $platT, 'GET', '/api/v1/organization/chart')->assertStatus(403);
    // per-capability separation with a custom role
    [$cu, $cuT] = lc_user($w['c'], 'employee', ['email' => 'cap@a.test']);
    lc_emp($w['c'], ['user_id' => $cu->id, 'email' => 'w.cap@a.test']);
    lc_role($w['c'], ['organization.positions.manage'], $cu);
    lc_call($this, $cuT, 'POST', '/api/v1/organization/positions', ['code' => 'CAP', 'title' => 'Cap', 'reason' => OR_R])->assertStatus(201);
    lc_call($this, $cuT, 'POST', '/api/v1/organization/positions', ['code' => 'CAP2', 'title' => 'Cap', 'parent_id' => $p, 'reason' => OR_R])->assertStatus(403); // reporting needs its own permission
    lc_call($this, $cuT, 'POST', "/api/v1/organization/positions/$p/assignments", ['employee_id' => $w['eHr']->id, 'type' => 'deputy', 'reason' => OR_R])->assertStatus(403);
    lc_call($this, $cuT, 'GET', "/api/v1/organization/positions/$p/history")->assertStatus(403);
    // permission loss takes effect immediately (revalidated under lock)
    DB::table('user_roles')->where('user_id', $cu->id)->delete();
    PermissionResolver::flush();
    lc_call($this, $cuT, 'POST', '/api/v1/organization/positions', ['code' => 'CAP3', 'title' => 'Cap', 'reason' => OR_R])->assertStatus(403);
});

test('no automatic RBAC elevation: holding any position grants nothing', function () {
    $w = lc_world();
    $ceo = or_pos($this, $w['hrT'], 'CEO');
    $rolesBefore = DB::table('user_roles')->count();
    $permsBefore = DB::table('role_permissions')->count();
    $usersBefore = User::withoutGlobalScopes()->count();
    foreach (['primary'] as $type) {
        or_assign($this, $w['hrT'], $ceo, $w['eEmp']->id, $type);
    }
    expect(DB::table('user_roles')->count())->toBe($rolesBefore)->and(DB::table('role_permissions')->count())->toBe($permsBefore)->and(User::withoutGlobalScopes()->count())->toBe($usersBefore);
    foreach (['/api/v1/access/roles', '/api/v1/employees', '/api/v1/organization/positions'] as $u) {
        lc_call($this, $w['empT'], 'GET', $u)->assertStatus(403);
    }
    lc_call($this, $w['empT'], 'POST', '/api/v1/organization/positions', ['code' => 'Q', 'title' => 'Q', 'reason' => OR_R])->assertStatus(403);
    // an employee without a login account can hold a position
    or_assign($this, $w['hrT'], or_pos($this, $w['hrT'], 'NOLOGIN'), $w['eNone']->id)->assertJsonPath('data.employee.id', $w['eNone']->id);
});

test('self protection: HR cannot assign or re-place itself, nor reparent or archive a position it holds (it CAN release itself, see the corrections tests)', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'P');
    $q = or_pos($this, $w['hrT'], 'Q');
    or_assign($this, $w['hrT'], $p, $w['eHr']->id, 'primary', [], 403);
    $a = or_assign($this, $w['saT'], $p, $w['eHr']->id)->json('data.id'); // another administrator may assign HR
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$a/placement", ['reason' => OR_R])->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/reparent", ['parent_id' => $q, 'reason' => OR_R])->assertStatus(403);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/archive", ['reason' => OR_R])->assertStatus(403);
    lc_call($this, $w['saT'], 'POST', "/api/v1/organization/positions/$p/reparent", ['parent_id' => $q, 'reason' => OR_R])->assertOk();
    // the employee sees its own responsibilities read-only in My Profile
    $own = lc_call($this, $w['hrT'], 'GET', '/api/v1/me/organization')->assertOk()->json('data.responsibilities');
    expect($own)->toHaveCount(1)->and($own[0]['position']['code'])->toBe('P')->and($own[0]['reports_to']['position_id'])->toBe($q);
});

test('employment lifecycle: termination ends assignments, keeps history and vacancy, nothing is restored on reactivation, former employees cannot be assigned', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'P');
    $q = or_pos($this, $w['hrT'], 'Q');
    $r = or_pos($this, $w['hrT'], 'R');
    $e = $w['eEmp'];
    $a1 = or_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => '2026-01-01'])->json('data.id');
    $a2 = or_assign($this, $w['hrT'], $q, $e->id, 'deputy', ['starts_on' => '2026-01-01'])->json('data.id');
    $a3 = or_assign($this, $w['hrT'], $r, $e->id, 'primary', ['starts_on' => '2099-01-01'])->json('data.id');
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason', 'termination_date' => '2026-11-01'])->assertOk();
    $rows = OrgPositionAssignment::withoutGlobalScopes()->whereIn('id', [$a1, $a2, $a3])->get()->keyBy('id');
    expect($rows[$a1]->status)->toBe('ended')->and($rows[$a1]->ends_on->toDateString())->toBe('2026-11-01')->and($rows[$a1]->end_cause)->toBe('employee_terminated')->and((bool) $rows[$a1]->is_primary_placement)->toBeFalse()
        ->and($rows[$a2]->status)->toBe('ended')->and($rows[$a3]->status)->toBe('cancelled');
    // position stays, shows as vacant, former employee not a current incumbent
    $n = or_node(or_chart($this, $w['hrT']), $p);
    expect($n['vacant'])->toBeTrue()->and($n['primary_id'])->toBeNull();
    // history is preserved and visible to history viewers
    $h = lc_call($this, $w['hrT'], 'GET', "/api/v1/organization/positions/$p/history")->json('data.assignments');
    expect($h)->toHaveCount(1)->and($h[0]['employee']['terminated'])->toBeTrue();
    // eligibility
    or_assign($this, $w['hrT'], $p, $e->id, 'primary', [], 422);
    // reactivation restores nothing
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/reactivate', ['reason' => 'Synthetic reactivation reason'])->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->whereIn('id', [$a1, $a2, $a3])->where('status', 'active')->count())->toBe(0);
    expect(or_node(or_chart($this, $w['hrT']), $p)['vacant'])->toBeTrue();
    expect(AuditLog::withoutGlobalScopes()->where('action', 'org.assignment.ended')->count())->toBe(3);
});

test('account unlink, deactivation and employee deletion: only deletion ends assignments (it removes the employee); accounts never change', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'P');
    $q = or_pos($this, $w['hrT'], 'Q');
    $a = or_assign($this, $w['hrT'], $p, $w['eEmp']->id)->json('data.id');
    $acct = fn ($op) => "/api/v1/employees/{$w['eEmp']->id}/account/$op";
    lc_call($this, $w['hrT'], 'POST', $acct('deactivate'), lc_body($w['eEmp']))->assertOk();
    lc_call($this, $w['hrT'], 'POST', $acct('unlink'), lc_body($w['eEmp']))->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->find($a)->status)->toBe('active');
    expect(or_node(or_chart($this, $w['hrT']), $p)['primary_id'])->toBe($w['eEmp']->id); // login state is irrelevant to the position
    $b = or_assign($this, $w['hrT'], $q, $w['eNone']->id)->json('data.id');
    lc_call($this, $w['hrT'], 'DELETE', '/api/v1/employees/'.$w['eNone']->id)->assertOk();
    expect(OrgPositionAssignment::withoutGlobalScopes()->find($b)->status)->toBe('ended');
});

test('legacy compatibility: manager_id, team leader flags and manager-based authorization are untouched by the organization engine', function () {
    $w = lc_world();
    $mgr = $w['eHr2'];
    $sub = $w['eEmp'];
    Employee::withoutGlobalScopes()->whereKey($sub->id)->update(['manager_id' => $mgr->id]);
    $before = DB::table('employees')->orderBy('id')->get(['id', 'manager_id', 'team_leader_id', 'is_team_leader', 'is_hod', 'is_ceo'])->toJson();
    $p = or_pos($this, $w['hrT'], 'P');
    $q = or_pos($this, $w['hrT'], 'Q', ['parent_id' => $p]);
    or_assign($this, $w['hrT'], $p, $sub->id);
    or_assign($this, $w['hrT'], $q, $mgr->id);
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$q/reparent", ['parent_id' => null, 'reason' => OR_R])->assertOk();
    expect(DB::table('employees')->orderBy('id')->get(['id', 'manager_id', 'team_leader_id', 'is_team_leader', 'is_hod', 'is_ceo'])->toJson())->toBe($before);
    $fresh = Employee::withoutGlobalScopes()->find($sub->id);
    expect($fresh->manager_id)->toBe($mgr->id)->and($fresh->isManagedBy($mgr))->toBeTrue();
    // the employee API still serves the legacy direct manager and the position reporting is a different, explicit concept
    lc_call($this, $w['hrT'], 'GET', '/api/v1/employees/'.$sub->id)->assertOk()->assertJsonPath('data.manager_id', $mgr->id);
});

test('audit is atomic: if the audit row cannot be written the organizational change rolls back', function () {
    $w = lc_world();
    // seed directly: the router caches controller instances per route, so the failing logger must be bound before the first API call
    $p = DB::table('org_positions')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $w['c']->id, 'code' => 'KEEP', 'title' => 'Keep', 'kind' => 'position', 'status' => 'active', 'display_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $mock = Mockery::mock(AuditLogger::class);
    $mock->shouldReceive('log')->andReturn(null);
    app()->instance(AuditLogger::class, $mock);
    app('auth')->forgetGuards();
    $res = lc_call($this, $w['hrT'], 'POST', '/api/v1/organization/positions', ['code' => 'LOST', 'title' => 'Lost', 'reason' => OR_R]);
    expect($res->status())->toBeGreaterThanOrEqual(500);
    expect(OrgPosition::withoutGlobalScopes()->where('code', 'LOST')->exists())->toBeFalse();
    $res = lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/positions/$p/assignments", ['employee_id' => $w['eNone']->id, 'type' => 'primary', 'reason' => OR_R]);
    expect($res->status())->toBeGreaterThanOrEqual(500)->and(OrgPositionAssignment::withoutGlobalScopes()->count())->toBe(0);
});

test('provisioning and reconciliation: org permissions are added idempotently, custom roles untouched, HR still has no RBAC', function () {
    $w = lc_world();
    $role = DB::table('roles')->where('company_id', $w['c']->id)->where('key', 'hr_administrator')->first();
    $custom = lc_role($w['c'], ['employees.view']);
    DB::table('role_permissions')->where('role_id', $role->id)->where('permission', 'like', 'organization.%')->delete();
    $customBefore = DB::table('role_permissions')->where('role_id', $custom)->pluck('permission')->all();
    $r = app(RoleReconciler::class);
    $dry = $r->reconcile($w['c'], true);
    expect(DB::table('role_permissions')->where('role_id', $role->id)->where('permission', 'like', 'organization.%')->count())->toBe(0);
    $r->reconcile($w['c'], false);
    $have = DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission')->all();
    expect(collect($have)->filter(fn ($p) => str_starts_with($p, 'organization.'))->count())->toBe(4)->and(collect($have)->filter(fn ($p) => str_starts_with($p, 'roles.') || str_starts_with($p, 'permissions.'))->count())->toBe(0);
    $snapshot = DB::table('role_permissions')->pluck('permission', 'id')->toJson();
    $r->reconcile($w['c'], false);
    expect(DB::table('role_permissions')->pluck('permission', 'id')->toJson())->toBe($snapshot);
    expect(DB::table('role_permissions')->where('role_id', $custom)->pluck('permission')->all())->toBe($customBefore);
});

test('effective dates use the company timezone', function () {
    $c = Company::factory()->create();
    DB::table('companies')->where('id', $c->id)->update(['timezone' => 'Pacific/Kiritimati']); // UTC+14
    $ahead = OrgService::today($c->id);
    DB::table('companies')->where('id', $c->id)->update(['timezone' => 'Pacific/Pago_Pago']); // UTC-11
    $behind = OrgService::today($c->id);
    expect($ahead > $behind)->toBeTrue();
});

test('org:preflight is read-only and reports legacy data without inventing positions', function () {
    $w = lc_world();
    Employee::withoutGlobalScopes()->whereKey($w['eEmp']->id)->update(['manager_id' => $w['eHr']->id, 'is_hod' => true]);
    $this->artisan('org:preflight --json')->assertExitCode(0);
    expect(OrgPosition::withoutGlobalScopes()->count())->toBe(0)->and(OrgPositionAssignment::withoutGlobalScopes()->count())->toBe(0);
    expect(Employee::withoutGlobalScopes()->find($w['eEmp']->id)->manager_id)->toBe($w['eHr']->id);
});

test('employee organizational section: authorized view for HR with history, none for ordinary employees', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'P');
    $a = or_assign($this, $w['hrT'], $p, $w['eNone']->id)->json('data.id');
    lc_call($this, $w['hrT'], 'POST', "/api/v1/organization/assignments/$a/end", ['reason' => OR_R])->assertOk();
    $res = lc_call($this, $w['hrT'], 'GET', '/api/v1/employees/'.$w['eNone']->id.'/organization')->assertOk();
    expect($res->json('data.responsibilities'))->toHaveCount(0)->and($res->json('data.history'))->toHaveCount(1)->and($res->json('data.can_manage'))->toBeTrue();
    lc_call($this, $w['empT'], 'GET', '/api/v1/employees/'.$w['eNone']->id.'/organization')->assertStatus(403);
});

test('same-day reassignment after a lifecycle end is refused with the earliest available date (inclusive end), history intact; the next day succeeds', function () {
    $w = lc_world();
    $p = or_pos($this, $w['hrT'], 'SD');
    $e = $w['eEmp'];
    $a1 = or_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => '2026-01-01'])->json('data.id');
    $today = OrgService::today($e->company_id);
    $tomorrow = Carbon\Carbon::parse($today)->addDay()->toDateString();
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/terminate', ['reason' => 'Synthetic termination reason'])->assertOk();
    lc_call($this, $w['hrT'], 'POST', '/api/v1/employees/'.$e->id.'/reactivate', ['reason' => 'Synthetic reactivation reason'])->assertOk();
    $res = or_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => $today], 409);
    expect($res->json('code'))->toBe('duplicate_assignment')->and($res->json('earliest_start'))->toBe($tomorrow);
    // nothing changed: the ended row is untouched and no new row exists
    $row = OrgPositionAssignment::withoutGlobalScopes()->find($a1);
    expect($row->status)->toBe('ended')->and($row->ends_on->toDateString())->toBe($today)
        ->and(OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $p)->count())->toBe(1);
    or_assign($this, $w['hrT'], $p, $e->id, 'primary', ['starts_on' => $tomorrow], 201);
    // an open-ended live assignment carries no earliest date
    $res2 = or_assign($this, $w['hrT'], $p, $e->id, 'deputy', ['starts_on' => $tomorrow], 409);
    expect($res2->json('code'))->toBe('duplicate_assignment')->and($res2->json())->not->toHaveKey('earliest_start');
});
