<?php

namespace App\Organization;

use App\Enums\EmploymentStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgPosition;
use App\Models\OrgPositionAssignment;
use App\Models\User;
use App\Rbac\PermissionResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.4 privacy-safe organizational read model. It exposes ONLY: display name, picture availability,
 * position, department, job title, general reporting relationships, vacancies and acting/deputy facts.
 * It never selects national id, phone, birth date, email, salary, account state, roles or permissions.
 * Reads are tenant-bound by explicit company_id on every query.
 */
class OrgReadModel
{
    public const MANAGE_PERMS = ['organization.positions.manage', 'organization.assignments.manage', 'organization.reporting.manage', 'organization.history.view'];

    /** The caller must be a current (not terminated, not deleted) employee of the tenant. */
    public static function currentEmployee(User $user): ?Employee
    {
        if ($user->isSuperAdmin() || ! $user->company_id) {
            return null;
        }

        return Employee::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)
            ->whereNull('deleted_at')->where('employment_status', '!=', EmploymentStatus::Terminated->value)->first();
    }

    public static function canManageAny(User $user): bool
    {
        foreach (self::MANAGE_PERMS as $p) {
            if (PermissionResolver::has($user, $p)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function card(Employee $e, ?int $positionCount = null): array
    {
        $card = [
            'id' => $e->id,
            'name' => trim($e->first_name.' '.$e->last_name),
            'job_title' => $e->jobTitle?->name,
            'department' => $e->department ? ['id' => $e->department->id, 'name' => $e->department->name] : null,
            'has_picture' => $e->avatar_path !== null,
            'picture_version' => $e->avatar_updated_at?->timestamp,
        ];
        if ($positionCount !== null) {
            $card['position_count'] = $positionCount;
        }

        return $card;
    }

    /** Current effective assignments: status active, inside the date window, employee current. */
    public static function effective(int $companyId, ?array $positionIds = null)
    {
        $today = OrgService::today($companyId);
        $q = OrgPositionAssignment::withoutGlobalScopes()->where('org_position_assignments.company_id', $companyId)->where('org_position_assignments.status', 'active')
            ->where('starts_on', '<=', $today)->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('employees as e')->whereColumn('e.id', 'org_position_assignments.employee_id')
                ->whereNull('e.deleted_at')->where('e.employment_status', '!=', 'terminated'));
        if ($positionIds !== null) {
            $q->whereIn('position_id', $positionIds);
        }

        return $q;
    }

    /** @return array<string, mixed> */
    public function chart(User $user, bool $includeArchived = false): array
    {
        $cid = $user->company_id;
        $manage = self::canManageAny($user);
        $positions = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->when(! ($includeArchived && $manage), fn ($q) => $q->where('status', 'active'), fn ($q) => $q->where('status', '!=', 'removed'))
            ->orderBy('display_order')->orderBy('id')->get();
        $assignments = self::effective($cid, $positions->pluck('id')->all())->get(['id', 'position_id', 'employee_id', 'type', 'starts_on', 'ends_on', 'is_primary_placement']);
        $employees = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $assignments->pluck('employee_id')->unique()->all())
            ->with(['department', 'jobTitle'])->get()->keyBy('id');
        $counts = $assignments->groupBy('employee_id')->map->count();
        $byPos = $assignments->groupBy('position_id');
        $depts = Department::withoutGlobalScopes()->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        // A deleted department that archived positions still reference stays visible (flagged) so history is never orphaned.
        $deletedRef = Department::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('deleted_at')->whereIn('id', $positions->pluck('department_id')->filter()->unique()->all())->orderBy('name')->get(['id', 'name']);
        $memberCounts = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')
            ->whereNotNull('department_id')->groupBy('department_id')->selectRaw('department_id, count(*) as c')->pluck('c', 'department_id');

        $todayStr = OrgService::today($cid);
        $reserved = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->whereIn('position_id', $positions->pluck('id')->all())->where('type', 'primary')
            ->where('status', 'ended')->whereNotNull('ends_on')->where('ends_on', '>=', $todayStr)->groupBy('position_id')->selectRaw('position_id, max(ends_on) as m')->pluck('m', 'position_id')
            ->map(fn ($m) => Carbon::parse($m)->addDay()->toDateString())->all();
        $nodes = [];
        foreach ($positions as $p) {
            $rows = $byPos->get($p->id, collect());
            $primary = $rows->firstWhere('type', 'primary');
            $acting = $rows->firstWhere('type', 'acting');
            $deputies = $rows->where('type', 'deputy')->pluck('employee_id')->values()->all();
            $primaryIds = $rows->where('type', 'primary')->pluck('employee_id')->unique()->values()->all();
            $nodes[] = [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'title_en' => $p->title_en, 'title_ar' => $p->title_ar,
                'kind' => $p->kind, 'classification' => $p->classification, 'status' => $p->status,
                'department_id' => $p->department_id, 'parent_id' => $p->parent_id, 'display_order' => $p->display_order,
                'vacant' => $p->kind === 'position' && $primary === null,
                // F05: a seat that reads vacant today but is still held by a closed window (for example a holder leaving on a future date): first day a replacement can start.
                'available_from' => $p->kind === 'position' && $primary === null && $p->occupancy_type === 'single' ? ($reserved[$p->id] ?? null) : null,
                'occupancy_type' => $p->occupancy_type, 'capacity' => $p->capacity, 'headcount' => count($primaryIds), 'primary_ids' => $primaryIds,
                'primary_id' => $primary?->employee_id, 'acting_id' => $acting?->employee_id, 'deputy_ids' => $deputies,
                // Precedence: acting, then primary. A deputy is never the effective holder.
                'effective_holder_id' => $acting?->employee_id ?? $primary?->employee_id,
            ];
        }
        $people = [];
        foreach ($employees as $e) {
            $people[$e->id] = self::card($e, $counts->get($e->id, 0));
        }
        $placement = $assignments->where('is_primary_placement', true)->pluck('position_id', 'employee_id');
        foreach ($people as $id => &$pc) {
            $pc['placement_position_id'] = $placement->get($id);
        }
        unset($pc);

        $out = [
            'company' => ['name' => DB::table('companies')->where('id', $cid)->value('name')],
            'generated_on' => OrgService::today($cid),
            'positions' => $nodes,
            'people' => (object) $people,
            'departments' => $depts->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'member_count' => (int) ($memberCounts[$d->id] ?? 0)])->values(),
            'deleted_departments' => $deletedRef->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'can_manage' => $manage,
        ];
        // F04: ordinary employees without a position, grouped by department (null = no department). Privacy-safe cards only (same fields as every other
        // person card); no account is required; no reporting line is invented. Capped per group, with the true count, for very large tenants.
        $out['unassigned_groups'] = $this->unassignedGroups($cid, $depts);
        if ($manage) {
            $out['unassigned_count'] = $this->unassignedQuery($cid)->count();
        }

        return $out;
    }

    public const GROUP_CAP = 200;

    /** @return list<array<string, mixed>> */
    private function unassignedGroups(int $cid, $depts): array
    {
        $rows = $this->unassignedQuery($cid)->with(['department', 'jobTitle'])->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get();
        $by = $rows->groupBy(fn ($e) => $e->department_id ?? 0);
        $out = [];
        foreach ($depts as $d) {
            if ($by->has($d->id)) {
                $g = $by->get($d->id);
                $out[] = ['department_id' => $d->id, 'name' => $d->name, 'count' => $g->count(), 'truncated' => $g->count() > self::GROUP_CAP, 'people' => $g->take(self::GROUP_CAP)->map(fn ($e) => self::card($e))->values()->all()];
            }
        }
        if ($by->has(0)) {
            $g = $by->get(0);
            $out[] = ['department_id' => null, 'name' => null, 'count' => $g->count(), 'truncated' => $g->count() > self::GROUP_CAP, 'people' => $g->take(self::GROUP_CAP)->map(fn ($e) => self::card($e))->values()->all()];
        }

        return $out;
    }

    private function unassignedQuery(int $cid)
    {
        $today = OrgService::today($cid);

        return Employee::withoutGlobalScopes()->where('company_id', $cid)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('org_position_assignments as a')->whereColumn('a.employee_id', 'employees.id')
                ->where('a.status', 'active')->where('a.starts_on', '<=', $today)->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $today)));
    }

    /** @return array<string, mixed> */
    public function unassigned(User $user, ?int $departmentId, int $perPage, ?string $search = null): array
    {
        $q = $this->unassignedQuery($user->company_id)->with(['department', 'jobTitle'])->orderBy('first_name')->orderBy('id');
        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(first_name) like ? escape \'\\\'', [$like])->orWhereRaw('lower(last_name) like ? escape \'\\\'', [$like])
                ->orWhereRaw("lower(first_name || ' ' || last_name) like ? escape '\\'", [$like]));
        }
        if ($departmentId) {
            $q->where('department_id', $departmentId);
        }
        $page = $q->paginate($perPage);

        return ['data' => collect($page->items())->map(fn ($e) => self::card($e))->values(), 'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]];
    }

    /** @return array<string, mixed> */
    public function departmentMembers(User $user, int $departmentId, int $perPage): ?array
    {
        if (! Department::withoutGlobalScopes()->where('company_id', $user->company_id)->whereNull('deleted_at')->whereKey($departmentId)->exists()) {
            return null;
        }
        $page = Employee::withoutGlobalScopes()->where('company_id', $user->company_id)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')
            ->where('department_id', $departmentId)->with(['department', 'jobTitle'])->orderBy('first_name')->orderBy('id')->paginate($perPage);

        return ['data' => collect($page->items())->map(fn ($e) => self::card($e))->values(), 'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]];
    }

    /** Limited employee panel (any eligible employee): card plus current organizational responsibilities. */
    public function person(User $user, int $employeeId): ?array
    {
        $e = Employee::withoutGlobalScopes()->where('company_id', $user->company_id)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')
            ->with(['department', 'jobTitle'])->whereKey($employeeId)->first();
        if (! $e) {
            return null;
        }

        return ['employee' => self::card($e)] + ['responsibilities' => $this->responsibilities($user->company_id, $e->id, false)];
    }

    /** @return list<array<string, mixed>> */
    public function responsibilities(int $cid, int $employeeId, bool $includeFuture): array
    {
        $today = OrgService::today($cid);
        $q = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('employee_id', $employeeId)->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
        if (! $includeFuture) {
            $q->where('starts_on', '<=', $today);
        }
        $rows = $q->orderBy('starts_on')->get();
        $positions = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $rows->pluck('position_id')->all())->get()->keyBy('id');
        $parents = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $positions->pluck('parent_id')->filter()->all())->get()->keyBy('id');
        $holders = self::effective($cid, $parents->keys()->all())->whereIn('type', ['primary', 'acting'])->get(['position_id', 'employee_id', 'type']);
        $holderEmp = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $holders->pluck('employee_id')->all())->get()->keyBy('id');

        return $rows->map(function ($a) use ($positions, $parents, $holders, $holderEmp, $today) {
            $p = $positions[$a->position_id];
            $parent = $p->parent_id ? ($parents[$p->parent_id] ?? null) : null;
            $h = $parent ? ($holders->where('position_id', $parent->id)->firstWhere('type', 'acting') ?? $holders->where('position_id', $parent->id)->firstWhere('type', 'primary')) : null;

            return [
                'assignment_id' => $a->id, 'type' => $a->type, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
                'is_primary_placement' => (bool) $a->is_primary_placement, 'scheduled' => $a->starts_on->toDateString() > $today,
                'position' => ['id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'title_en' => $p->title_en, 'title_ar' => $p->title_ar, 'status' => $p->status],
                'reports_to' => $parent ? ['position_id' => $parent->id, 'title' => $parent->title, 'title_en' => $parent->title_en, 'title_ar' => $parent->title_ar,
                    'holder' => $h && isset($holderEmp[$h->employee_id]) ? trim($holderEmp[$h->employee_id]->first_name.' '.$holderEmp[$h->employee_id]->last_name) : null] : null,
            ];
        })->values()->all();
    }
}
