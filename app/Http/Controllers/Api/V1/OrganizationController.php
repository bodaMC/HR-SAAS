<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AvatarService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrgPosition;
use App\Models\OrgPositionAssignment;
use App\Organization\OrgReadModel;
use App\Organization\OrgService;
use App\Rbac\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Phase 3.4 organization endpoints. Reads: every current employee (privacy-safe model). Writes: explicit permissions, enforced again under lock. */
class OrganizationController extends Controller
{
    public function __construct(private OrgService $org, private OrgReadModel $read, private AvatarService $avatars) {}

    private function viewer(Request $r): void
    {
        // F05: current employees read; so do accounts that hold an organization permission even without a linked employee profile (HR, System Super
        // Admin). Reading grants nothing: every write still checks its own permission.
        $u = $r->user();
        if ($u->isSuperAdmin() || ! $u->company_id || (! OrgReadModel::currentEmployee($u) && ! OrgReadModel::canManageAny($u))) {
            throw OrgService::deny(403, 'forbidden');
        }
    }

    private function admin(Request $r): void
    {
        $this->viewer($r);
        if (! OrgReadModel::canManageAny($r->user())) {
            throw OrgService::deny(403, 'forbidden');
        }
    }

    private function need(Request $r, string $perm): void
    {
        $this->viewer($r);
        if (! PermissionResolver::has($r->user(), $perm)) {
            throw OrgService::deny(403, 'forbidden');
        }
    }

    // ─── general read-only view ───

    public function chart(Request $r): JsonResponse
    {
        $this->viewer($r);

        return response()->json(['data' => $this->read->chart($r->user(), $r->boolean('include_archived'))]);
    }

    public function person(Request $r, int $employee): JsonResponse
    {
        $this->viewer($r);
        $d = $this->read->person($r->user(), $employee);
        if (! $d) {
            throw OrgService::deny(404, 'not_found');
        }

        return response()->json(['data' => $d]);
    }

    public function departmentMembers(Request $r, int $department): JsonResponse
    {
        $this->viewer($r);
        $d = $this->read->departmentMembers($r->user(), $department, min(100, max(1, (int) $r->input('per_page', 25))));
        if (! $d) {
            throw OrgService::deny(404, 'not_found');
        }

        return response()->json($d);
    }

    public function picture(Request $r, int $employee): BinaryFileResponse
    {
        $this->viewer($r);
        $e = Employee::withoutGlobalScopes()->where('company_id', $r->user()->company_id)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')->whereKey($employee)->first();
        $file = $e ? $this->avatars->stream($e) : null;
        if (! $file) {
            throw OrgService::deny(404, 'not_found');
        }

        return response()->file($file, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="picture.jpg"',
        ]);
    }

    public function myOrganization(Request $r): JsonResponse
    {
        $e = OrgReadModel::currentEmployee($r->user());
        if (! $e) {
            throw OrgService::deny(403, 'forbidden');
        }

        return response()->json(['data' => ['responsibilities' => $this->read->responsibilities($e->company_id, $e->id, true)]]);
    }

    public function employeeOrganization(Request $r, int $employee): JsonResponse
    {
        $this->viewer($r);
        if (! PermissionResolver::has($r->user(), 'employees.view')) {
            throw OrgService::deny(403, 'forbidden');
        }
        $e = Employee::withoutGlobalScopes()->where('company_id', $r->user()->company_id)->whereNull('deleted_at')->whereKey($employee)->first();
        if (! $e) {
            throw OrgService::deny(404, 'not_found');
        }
        $out = ['responsibilities' => $this->read->responsibilities($e->company_id, $e->id, true)];
        if (PermissionResolver::has($r->user(), 'organization.history.view')) {
            $out['history'] = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $e->company_id)->where('employee_id', $e->id)->orderByDesc('starts_on')->orderByDesc('id')->get()
                ->map(fn ($a) => $this->assignmentRow($a))->values();
        }
        $out['can_manage'] = PermissionResolver::has($r->user(), 'organization.assignments.manage') && $e->user_id !== $r->user()->id;

        return response()->json(['data' => $out]);
    }

    // ─── administration reads ───

    public function positions(Request $r): JsonResponse
    {
        $this->admin($r);
        $cid = $r->user()->company_id;
        $v = $r->validate([
            'search' => ['nullable', 'string', 'max:100'], 'department_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['active', 'archived', 'removed', 'all'])],
            'occupancy' => ['nullable', Rule::in(['vacant', 'occupied'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = OrgPosition::withoutGlobalScopes()->where('company_id', $cid);
        $status = $v['status'] ?? 'active';
        if ($status !== 'all') {
            $q->where('status', $status);
        } else {
            $q->where('status', '!=', 'removed'); // removed positions are listed only on request (status=removed)
        }
        if (! empty($v['department_id'])) {
            $q->where('department_id', $v['department_id']);
        }
        if (! empty($v['search'])) {
            $like = '%'.mb_strtolower(addcslashes($v['search'], '\\%_')).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(title) like ?', [$like])
                ->orWhereRaw('lower(coalesce(title_en, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(title_ar, \'\')) like ?', [$like]));
        }
        $has = fn ($types) => OrgReadModel::effective($cid)->whereColumn('org_position_assignments.position_id', 'org_positions.id')->whereIn('type', $types)->toBase()->select(DB::raw(1));
        if (($v['occupancy'] ?? null) === 'vacant') {
            $q->where('kind', 'position')->whereNotExists($has(['primary']));
        } elseif (($v['occupancy'] ?? null) === 'occupied') {
            $q->whereExists($has(['primary']));
        }
        $page = $q->orderBy('display_order')->orderBy('code')->paginate((int) ($v['per_page'] ?? 25));
        $rows = collect($page->items());

        return response()->json(['data' => $this->rows($cid, $rows), 'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]]);
    }

    /** @param Collection<int, OrgPosition> $positions */
    private function rows(int $cid, $positions): array
    {
        $eff = OrgReadModel::effective($cid, $positions->pluck('id')->all())->get(['position_id', 'employee_id', 'type']);
        $emps = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $eff->pluck('employee_id')->all())->with(['department', 'jobTitle'])->get()->keyBy('id');
        $parents = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $positions->pluck('parent_id')->filter()->all())->get(['id', 'code', 'title'])->keyBy('id');
        $depts = DB::table('departments')->where('company_id', $cid)->whereIn('id', $positions->pluck('department_id')->filter()->all())->pluck('name', 'id');

        return $positions->map(function (OrgPosition $p) use ($eff, $emps, $parents, $depts) {
            $rows = $eff->where('position_id', $p->id);
            $person = fn ($type) => ($x = $rows->firstWhere('type', $type)) && isset($emps[$x->employee_id]) ? OrgReadModel::card($emps[$x->employee_id]) : null;

            return [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'title_en' => $p->title_en, 'title_ar' => $p->title_ar, 'description' => $p->description,
                'kind' => $p->kind, 'classification' => $p->classification, 'status' => $p->status, 'display_order' => $p->display_order,
                'department' => $p->department_id ? ['id' => $p->department_id, 'name' => $depts[$p->department_id] ?? null] : null,
                'parent' => $p->parent_id && isset($parents[$p->parent_id]) ? ['id' => $p->parent_id, 'code' => $parents[$p->parent_id]->code, 'title' => $parents[$p->parent_id]->title] : null,
                'vacant' => $p->kind === 'position' && $rows->firstWhere('type', 'primary') === null,
                'occupancy_type' => $p->occupancy_type, 'capacity' => $p->capacity, 'headcount' => $rows->where('type', 'primary')->count(),
                'primaries' => $rows->where('type', 'primary')->map(fn ($x) => isset($emps[$x->employee_id]) ? OrgReadModel::card($emps[$x->employee_id]) : null)->filter()->values(),
                'primary' => $person('primary'), 'acting' => $person('acting'),
                'deputies' => $rows->where('type', 'deputy')->map(fn ($x) => isset($emps[$x->employee_id]) ? OrgReadModel::card($emps[$x->employee_id]) : null)->filter()->values(),
            ];
        })->values()->all();
    }

    public function show(Request $r, int $position): JsonResponse
    {
        $this->admin($r);
        $cid = $r->user()->company_id;
        $p = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereKey($position)->first();
        if (! $p) {
            throw OrgService::deny(404, 'not_found');
        }
        $row = $this->rows($cid, collect([$p]))[0];
        $today = OrgService::today($cid);
        $live = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('position_id', $p->id)->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->orderBy('starts_on')->get();
        $row['assignments'] = $live->map(fn ($a) => $this->assignmentRow($a, $today))->values();
        // FINAL-V3: assignments that closed recently (or whose closed window still holds the seat) so an administrator can void or reinstate a
        // mistaken one without needing the history permission. Voided rows are never listed here.
        $row['closed_assignments'] = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('position_id', $p->id)->where('status', 'ended')
            ->whereIn('end_cause', ['manual', 'replaced'])
            ->where(fn ($w) => $w->where('ends_on', '>=', $today)->orWhere('ended_at', '>=', now()->subDays(7)))->orderByDesc('ended_at')->limit(10)->get()
            ->map(fn ($a) => $this->assignmentRow($a, $today))->values();
        $row['children_count'] = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->where('parent_id', $p->id)->where('status', 'active')->count();
        $row['can_view_history'] = PermissionResolver::has($r->user(), 'organization.history.view');

        return response()->json(['data' => $row]);
    }

    private function assignmentRow(OrgPositionAssignment $a, ?string $today = null): array
    {
        $e = Employee::withoutGlobalScopes()->where('company_id', $a->company_id)->whereKey($a->employee_id)->first();
        $pos = OrgPosition::withoutGlobalScopes()->where('company_id', $a->company_id)->whereKey($a->position_id)->first(['id', 'code', 'title']);

        return [
            'id' => $a->id, 'type' => $a->type, 'status' => $a->status, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'is_primary_placement' => (bool) $a->is_primary_placement, 'reason' => $a->reason, 'end_cause' => $a->end_cause, 'end_reason' => $a->end_reason,
            'voided_at' => $a->voided_at?->toIso8601String(), 'correction_id' => $a->correction_id, 'replaces_assignment_id' => $a->replaces_assignment_id,
            'scheduled' => $today !== null && $a->status === 'active' && $a->starts_on->toDateString() > $today,
            'employee' => $e ? ['id' => $e->id, 'name' => trim($e->first_name.' '.$e->last_name), 'terminated' => $e->employment_status->value === 'terminated' || $e->trashed()] : null,
            'position' => $pos ? ['id' => $pos->id, 'code' => $pos->code, 'title' => $pos->title] : null,
        ];
    }

    public function history(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.history.view');
        $cid = $r->user()->company_id;
        $p = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereKey($position)->first();
        if (! $p) {
            throw OrgService::deny(404, 'not_found');
        }
        $assignments = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('position_id', $p->id)->orderByDesc('starts_on')->orderByDesc('id')->get()
            ->map(fn ($a) => $this->assignmentRow($a))->values();
        $titles = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->pluck('title', 'id');
        $reporting = DB::table('org_reporting_history')->where('company_id', $cid)->where('position_id', $p->id)->orderByDesc('id')->get()->map(fn ($h) => [
            'from_parent' => $h->from_parent_id ? ['id' => $h->from_parent_id, 'title' => $titles[$h->from_parent_id] ?? null] : null,
            'to_parent' => $h->to_parent_id ? ['id' => $h->to_parent_id, 'title' => $titles[$h->to_parent_id] ?? null] : null,
            'reason' => $h->reason, 'changed_at' => $h->changed_at,
        ])->values();

        return response()->json(['data' => ['assignments' => $assignments, 'reporting' => $reporting]]);
    }

    public function eligibleEmployees(Request $r): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $q = Employee::withoutGlobalScopes()->where('company_id', $r->user()->company_id)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')
            ->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $r->user()->id))->with(['department', 'jobTitle']);
        if ($s = trim((string) $r->input('q'))) {
            $like = '%'.mb_strtolower(addcslashes($s, '\\%_')).'%';
            $q->where(fn ($w) => $w->whereRaw("lower(first_name || ' ' || last_name) like ?", [$like])->orWhereRaw('lower(employee_number) like ?', [$like]));
        }

        return response()->json(['data' => $q->orderBy('first_name')->orderBy('id')->limit(25)->get()->map(fn ($e) => OrgReadModel::card($e))->values()]);
    }

    public function unassigned(Request $r): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');

        return response()->json($this->read->unassigned($r->user(), $r->input('department_id') ? (int) $r->input('department_id') : null, min(100, max(1, (int) $r->input('per_page', 25)))));
    }

    /** F04: every viewer may search ordinary employees who hold no position (privacy-safe cards). */
    public function peopleWithoutPosition(Request $r): JsonResponse
    {
        $this->viewer($r);

        return response()->json($this->read->unassigned($r->user(), $r->input('department_id') ? (int) $r->input('department_id') : null, min(100, max(1, (int) $r->input('per_page', 25))), $r->input('search') ? (string) $r->input('search') : null));
    }

    // ─── mutations ───

    private function positionRules(bool $create): array
    {
        return [
            'code' => [$create ? 'required' : 'sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'], 'title_ar' => ['nullable', 'string', 'max:160'],
            'department_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'], 'kind' => ['nullable', Rule::in(['position', 'body'])],
            'classification' => ['nullable', 'string', 'max:40'],
            'occupancy_type' => ['nullable', Rule::in(['single', 'multiple'])], 'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function store(Request $r): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');
        $d = $r->validate($this->positionRules(true) + ['parent_id' => ['nullable', 'integer']]);
        $p = $this->org->createPosition($r->user(), $d);

        return response()->json(['data' => $this->rows($p->company_id, collect([$p]))[0]], 201);
    }

    public function update(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');
        $d = $r->validate($this->positionRules(false));
        if ($r->has('parent_id')) {
            $d['parent_id'] = $r->input('parent_id');
        }
        $p = $this->org->updatePosition($r->user(), $position, $d);

        return response()->json(['data' => $this->rows($p->company_id, collect([$p]))[0]]);
    }

    public function archive(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');
        $r->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $res = $this->org->archivePosition($r->user(), $position, $r->input('reason'));

        return response()->json(['data' => $this->rows($res['position']->company_id, collect([$res['position']]))[0], 'assignments_ended' => $res['assignments_ended']]);
    }

    public function restore(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');
        $r->validate(['reason' => ['nullable', 'string', 'max:500'], 'department_id' => ['nullable', 'integer']]);
        $p = $this->org->restorePosition($r->user(), $position, $r->input('reason'), $r->has('department_id'), $r->input('department_id') === null ? null : (int) $r->input('department_id'));

        return response()->json(['data' => $this->rows($p->company_id, collect([$p]))[0]]);
    }

    public function destroy(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');
        $r->validate([
            'reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['required', 'accepted'],
            'resolutions' => ['nullable', 'array', 'max:200'], 'resolutions.*.assignment_id' => ['required', 'integer'], 'resolutions.*.action' => ['required', Rule::in(['void', 'end', 'move'])],
            'resolutions.*.target_position_id' => ['nullable', 'integer'],
            'children' => ['nullable', 'array'], 'children.parent_id' => ['nullable', 'integer'],
        ]);
        $res = $this->org->deletePosition($r->user(), $position, $r->input('reason'), $r->input('confirm'), (array) $r->input('resolutions', []), $r->has('children') ? (array) $r->input('children') : null);
        $snap = $res['snapshot'];

        return response()->json(['data' => ['id' => $snap['id'], 'code' => $snap['code'], 'deleted' => true, 'mode' => $res['mode'], 'summary' => $res['summary']]]);
    }

    /** UAT-02: what removal would touch (current assignments, children, history). Read only. */
    public function removalPreview(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.positions.manage');

        return response()->json(['data' => $this->org->removalPreview($r->user(), $position)]);
    }

    public function reparent(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.reporting.manage');
        $r->validate(['parent_id' => ['present', 'nullable', 'integer'], 'expected_parent_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:500']]);
        $res = $this->org->reparent($r->user(), $position, $r->input('parent_id') === null ? null : (int) $r->input('parent_id'), $r->input('reason'),
            $r->has('expected_parent_id'), $r->input('expected_parent_id') === null ? null : (int) $r->input('expected_parent_id'));

        return response()->json(['data' => $this->rows($res['position']->company_id, collect([$res['position']]))[0], 'branch_positions' => $res['branch_positions']]);
    }

    public function assign(Request $r, int $position): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $d = $r->validate([
            'employee_id' => ['required', 'integer'], 'type' => ['required', Rule::in(OrgService::TYPES)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'is_primary_placement' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $a = $this->org->assign($r->user(), $position, $d);

        return response()->json(['data' => $this->assignmentRow($a->refresh(), OrgService::today($a->company_id))], 201);
    }

    public function endAssignment(Request $r, int $assignment): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $d = $r->validate(['ends_on' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:500']]);
        $a = $this->org->endAssignment($r->user(), $assignment, $d['ends_on'] ?? null, ($d['reason'] ?? null));

        return response()->json(['data' => $this->assignmentRow($a->refresh(), OrgService::today($a->company_id))]);
    }

    public function voidAssignment(Request $r, int $assignment): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['required', 'boolean']]);
        $a = $this->org->voidAssignment($r->user(), $assignment, ($d['reason'] ?? null), $d['confirm']);

        return response()->json(['data' => $this->assignmentRow($a->refresh(), OrgService::today($a->company_id))]);
    }

    public function reinstateAssignment(Request $r, int $assignment): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $d = $r->validate(['ends_on' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['required', 'boolean'], 'restore_placement' => ['nullable', 'boolean']]);
        $a = $this->org->reinstateAssignment($r->user(), $assignment, $d['ends_on'] ?? null, ($d['reason'] ?? null), $d['confirm'], (bool) ($d['restore_placement'] ?? false));

        return response()->json(['data' => $this->assignmentRow($a->refresh(), OrgService::today($a->company_id))]);
    }

    public function replaceAssignment(Request $r, int $assignment): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $d = $r->validate([
            'employee_id' => ['required', 'integer'], 'mode' => ['required', Rule::in(['correction', 'handover'])],
            'effective_on' => ['nullable', 'date_format:Y-m-d'], 'is_primary_placement' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['required', 'boolean'],
        ]);
        $res = $this->org->replaceAssignment($r->user(), $assignment, $d);
        $today = OrgService::today($res['new']->company_id);

        return response()->json(['data' => ['id' => $res['new']->id, 'previous' => $this->assignmentRow($res['old'], $today), 'replacement' => $this->assignmentRow($res['new'], $today)]], 201);
    }

    public function placement(Request $r, int $assignment): JsonResponse
    {
        $this->need($r, 'organization.assignments.manage');
        $r->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $a = $this->org->setPlacement($r->user(), $assignment, $r->input('reason'));

        return response()->json(['data' => $this->assignmentRow($a->refresh(), OrgService::today($a->company_id))]);
    }
}
