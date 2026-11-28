<?php

namespace App\Organization;

use App\Enums\EmploymentStatus;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OrgPosition;
use App\Models\OrgPositionAssignment;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Phase 3.4 organizational engine: the ONLY writer of positions, assignments and reporting lines.
 *
 * Every mutation: one transaction; tenant row locked FOR UPDATE first (same lock as AccountService and RBAC
 * mutations, so org mutations and lifecycle mutations are serialized per tenant in a fixed order: tenant, then
 * employee, then position, then assignment); the actor and its permission are re-read under the lock;
 * all domain rules are re-validated under the lock; the audit row is part of the transaction (audit failure
 * rolls the change back). Nothing here reads or writes users' roles, permissions, accounts or manager_id.
 */
class OrgService
{
    public const TYPES = ['primary', 'acting', 'deputy'];

    public function __construct(private AuditLogger $audit) {}

    // ───────────────────────── plumbing ─────────────────────────

    /** @param array<string, string> $meta */
    public static function deny(int $status, string $code, array $meta = []): RbacDenied
    {
        return new RbacDenied($status, $code, __("organization.$code"), [], $meta);
    }

    public static function today(int $companyId): string
    {
        $tz = Company::withoutGlobalScopes()->whereKey($companyId)->value('timezone') ?: config('app.timezone');
        try {
            return Carbon::now($tz)->toDateString();
        } catch (\Throwable) {
            return Carbon::now(config('app.timezone'))->toDateString();
        }
    }

    /**
     * F03 department lifecycle rule (documented in the corrective report). Runs inside the caller's transaction.
     * Takes the SAME tenant lock as every position write, so a delete and a concurrent position create/update/move are serialized.
     * A department that still has ACTIVE positions cannot be deleted (409 department_has_positions, with the count); the administrator moves or
     * archives those positions first. ARCHIVED positions keep their historical department reference: the row is only soft-deleted and the read
     * models resolve the name including deleted departments, so no history is lost and nothing is orphaned.
     */
    public function assertDepartmentDeletable(int $companyId, int $departmentId): void
    {
        Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->first();
        $n = OrgPosition::withoutGlobalScopes()->where('company_id', $companyId)->where('department_id', $departmentId)->where('status', 'active')->count();
        if ($n > 0) {
            throw self::deny(409, 'department_has_positions', ['active_positions' => $n]);
        }
    }

    private function begin(User $actor, string $permission): User
    {
        Company::withoutGlobalScopes()->whereKey($actor->company_id)->lockForUpdate()->first();
        PermissionResolver::flush();
        $fresh = User::withoutGlobalScopes()->whereKey($actor->id)->first();
        PermissionResolver::flush();
        if (! $fresh || ! $fresh->is_active || $fresh->trashed() || $fresh->isSuperAdmin() || $fresh->company_id !== $actor->company_id
            || ! PermissionResolver::has($fresh, $permission)) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }

        // Authentication accounts and employee records are separate. An account that holds the explicit organization permission (HR, System Super Admin) may
        // manage without a linked employee profile; the absence of a profile never removes a granted capability and never adds one. Self-target
        // protection below still applies whenever the account HAS a profile.
        return $fresh;
    }

    private function actorEmployeeId(User $user): ?int
    {
        return Employee::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)
            ->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')->value('id');
    }

    /** UAT-05: the reason is optional. Empty becomes null (never a made-up text); a supplied reason keeps only the maximum length. */
    private function reason(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => [__('organization.reason_too_long')]]);
        }

        return $reason;
    }

    private function position(User $actor, int $id, bool $allowRemoved = false): OrgPosition
    {
        $p = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($id)->lockForUpdate()->first();
        // UAT-02: a removed (tombstoned) position behaves as if it were not there for every operation except restore.
        if (! $p || (! $allowRemoved && $p->isRemoved())) {
            throw self::deny(404, 'not_found');
        }

        return $p;
    }

    private function auditOrFail(string $action, $model, ?array $before, ?array $after, User $actor, ?string $reason): void
    {
        if ($this->audit->log($action, $model, $before, $after, $actor, $reason) === null) {
            throw new RuntimeException('Audit record could not be written; the change was rolled back.');
        }
    }

    private function snap(OrgPosition $p): array
    {
        return $p->only(['code', 'title', 'title_en', 'title_ar', 'department_id', 'parent_id', 'display_order', 'kind', 'classification', 'status', 'description', 'occupancy_type', 'capacity']);
    }

    /** True when the actor currently holds (any type) the position. Org management never edits the actor's own placement. */
    private function actorHolds(User $actor, int $positionId): bool
    {
        $eid = $this->actorEmployeeId($actor);

        return $eid !== null && $this->nonCancelled($positionId)->where('employee_id', $eid)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', self::today($actor->company_id)))->exists();
    }

    private function nonCancelled(int $positionId)
    {
        return OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $positionId)->where('status', '!=', 'cancelled');
    }

    private function overlapping($q, string $start, ?string $end)
    {
        return $q->where('starts_on', '<=', $end ?? '9999-12-31')
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $start));
    }

    /** Assignments that are still in force today: active and not past their end date (a future start counts as live). */
    private function live($q, string $today)
    {
        return $q->where('status', 'active')->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    /**
     * Natural expiry. An assignment whose end date has passed keeps status "active" until something touches it; that must not keep it in the
     * placement rule or block anything. Under the tenant lock, mark every expired row of the employee as ended (history is kept), clear its
     * placement flag and audit it. Idempotent. Returns the number of rows changed.
     */
    public function expireForEmployee(User $actor, int $employeeId): int
    {
        $today = self::today($actor->company_id);
        $rows = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('employee_id', $employeeId)
            ->where('status', 'active')->whereNotNull('ends_on')->where('ends_on', '<', $today)->lockForUpdate()->get();
        foreach ($rows as $a) {
            $before = $this->asnap($a);
            $a->forceFill(['status' => 'ended', 'is_primary_placement' => false, 'end_cause' => $a->end_cause ?? 'expired', 'ended_at' => $a->ended_at ?? now()])->save();
            $this->auditOrFail('org.assignment.expired', $a, $before, $this->asnap($a) + ['cause' => 'expired'], $actor, null);
        }

        return $rows->count();
    }

    /** Active placement-flagged assignments of the employee whose date window overlaps [start, end] (inclusive), optionally excluding one row. */
    private function placementClashes(int $companyId, int $employeeId, string $start, ?string $end, ?int $exceptId = null)
    {
        $q = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->where('status', 'active')->where('is_primary_placement', true);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $this->overlapping($q, $start, $end);
    }

    // ───────────────────────── positions ─────────────────────────

    /** @param array<string, mixed> $d */
    public function createPosition(User $actor, array $d): OrgPosition
    {
        return DB::transaction(function () use ($actor, $d) {
            $actor = $this->begin($actor, 'organization.positions.manage');
            $reason = $this->reason($d['reason'] ?? null);
            $parentId = $d['parent_id'] ?? null;
            if ($parentId !== null) {
                if (! PermissionResolver::has($actor, 'organization.reporting.manage')) {
                    throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
                }
                $this->assertParentUsable($actor, (int) $parentId);
            }
            $this->assertDepartment($actor, $d['department_id'] ?? null);
            $this->assertCodeFree($actor, (string) $d['code']);
            try {
                $p = OrgPosition::create([
                    'code' => $d['code'], 'title' => $d['title'], 'title_en' => $d['title_en'] ?? null, 'title_ar' => $d['title_ar'] ?? null,
                    'department_id' => $d['department_id'] ?? null, 'parent_id' => $parentId, 'description' => $d['description'] ?? null,
                    'display_order' => $d['display_order'] ?? 0, 'kind' => $d['kind'] ?? 'position', 'classification' => $d['classification'] ?? null,
                    'occupancy_type' => ($d['occupancy_type'] ?? 'single'), 'capacity' => (($d['occupancy_type'] ?? 'single') === 'multiple') ? ($d['capacity'] ?? null) : null,
                    'status' => 'active', 'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                throw self::deny(409, 'code_taken');
            }
            if ($parentId !== null) {
                DB::table('org_reporting_history')->insert([
                    'company_id' => $actor->company_id, 'position_id' => $p->id, 'from_parent_id' => null, 'to_parent_id' => $parentId,
                    'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
                ]);
            }
            $this->auditOrFail('org.position.created', $p, null, $this->snap($p), $actor, $reason);

            return $p;
        });
    }

    /** Occupancy changes, validated against every current and future primary assignment under the tenant lock. */
    private function applyOccupancy(User $actor, OrgPosition $p, array $d): void
    {
        if (! array_key_exists('occupancy_type', $d) && ! array_key_exists('capacity', $d)) {
            return;
        }
        $type = $d['occupancy_type'] ?? $p->occupancy_type;
        $cap = $type === 'multiple' ? (array_key_exists('capacity', $d) ? $d['capacity'] : $p->capacity) : null;
        if ($type === 'multiple' && $cap !== null && (int) $cap < 1) {
            throw ValidationException::withMessages(['capacity' => [__('organization.capacity_invalid')]]);
        }
        if ($type === $p->occupancy_type && ($cap === null ? null : (int) $cap) === ($p->capacity === null ? null : (int) $p->capacity)) {
            return;
        }
        $today = self::today($actor->company_id);
        $futureBase = $this->nonCancelled($p->id)->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
        $peak = $this->peakConcurrency($futureBase, 'primary', $today, null);
        // Also consider windows that start later than today: peakConcurrency evaluates every start point inside [today, open end].
        if ($type === 'single' && $peak > 1) {
            throw self::deny(409, 'occupancy_incompatible', ['peak' => $peak]);
        }
        if ($type === 'multiple' && $cap !== null && $peak > (int) $cap) {
            throw self::deny(409, 'capacity_below_occupancy', ['peak' => $peak, 'capacity' => (int) $cap]);
        }
        $p->occupancy_type = $type;
        $p->capacity = $cap === null ? null : (int) $cap;
    }

    /** @param array<string, mixed> $d */
    public function updatePosition(User $actor, int $id, array $d): OrgPosition
    {
        return DB::transaction(function () use ($actor, $id, $d) {
            $actor = $this->begin($actor, 'organization.positions.manage');
            $reason = $this->reason($d['reason'] ?? null);
            $p = $this->position($actor, $id);
            if ($p->isArchived()) {
                throw self::deny(409, 'position_archived');
            }
            if (array_key_exists('parent_id', $d)) {
                throw self::deny(422, 'use_reparent'); // reporting changes only through reparent()
            }
            $before = $this->snap($p);
            if (isset($d['code']) && $d['code'] !== $p->code) {
                $this->assertCodeFree($actor, (string) $d['code'], $p->id);
            }
            if (array_key_exists('department_id', $d)) {
                $this->assertDepartment($actor, $d['department_id']);
            }
            if (($d['kind'] ?? $p->kind) === 'body' && $this->nonCancelled($p->id)->where('status', 'active')->exists()) {
                throw self::deny(409, 'body_has_assignments');
            }
            $this->applyOccupancy($actor, $p, $d);
            $p->fill(array_intersect_key($d, array_flip(['code', 'title', 'title_en', 'title_ar', 'department_id', 'description', 'display_order', 'kind', 'classification'])));
            $p->updated_by = $actor->id;
            try {
                $p->save();
            } catch (QueryException $e) {
                throw self::deny(409, 'code_taken');
            }
            $this->auditOrFail('org.position.updated', $p, $before, $this->snap($p), $actor, $reason);

            return $p;
        });
    }

    /**
     * F10 permanent deletion of a position created by mistake. Only a position with NO trace worth preserving can be removed:
     * no assignment of any status (cancelled rows count), no child position (any status), no reporting-history row other than its own creation row,
     * and no other position's history pointing at it. Nothing is ever cascaded. The full snapshot goes into the audit record first.
     */
    /**
     * UAT-02: what removing a position would touch. Read only, no lock. The screen shows it before anything is changed.
     *
     * @return array<string, mixed>
     */
    public function removalPreview(User $actor, int $id): array
    {
        $cid = $actor->company_id;
        $p = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereKey($id)->first();
        if (! $p || $p->isRemoved()) {
            throw self::deny(404, 'not_found');
        }
        $today = self::today($cid);
        $all = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('position_id', $p->id)->orderBy('starts_on')->get();
        $emps = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $all->pluck('employee_id')->all())->get()->keyBy('id');
        $isLive = fn (OrgPositionAssignment $a) => $a->status === 'active' && ($a->ends_on === null || $a->ends_on->toDateString() >= $today);
        $live = $all->filter($isLive)->map(function (OrgPositionAssignment $a) use ($emps, $today, $actor) {
            $e = $emps[$a->employee_id] ?? null;
            $own = $e && $e->user_id !== null && $e->user_id === $actor->id;

            return ['assignment_id' => $a->id, 'employee_id' => $a->employee_id, 'employee_name' => $e ? trim($e->first_name.' '.$e->last_name) : null, 'employee_number' => $e?->employee_number,
                'type' => $a->type, 'status' => $a->starts_on->toDateString() > $today ? 'scheduled' : 'active', 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
                'is_primary_placement' => (bool) $a->is_primary_placement, 'own_record' => $own, 'actions' => $own ? ['void', 'end'] : ['void', 'end', 'move']];
        })->values()->all();
        $cancelled = $all->where('status', 'cancelled')->count();
        $closed = $all->filter(fn ($a) => $a->status === 'ended' || ($a->status === 'active' && ! $isLive($a)))->count();
        $children = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->where('parent_id', $p->id)->where('status', '!=', 'removed')->orderBy('code')->get(['id', 'code', 'title', 'status'])
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'status' => $c->status])->all();
        $history = $this->hasReportingHistory($cid, $p);
        $mode = ($live !== []) ? 'current' : (($closed > 0 || $history) ? 'history' : (($cancelled > 0) ? 'cancelled_only' : (($children !== []) ? 'children_only' : 'unused')));

        return ['position' => ['id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'status' => $p->status, 'occupancy_type' => $p->occupancy_type, 'capacity' => $p->capacity],
            'mode' => $mode, 'live_assignments' => $live, 'closed_assignments' => $closed, 'cancelled_assignments' => $cancelled, 'has_reporting_history' => $history,
            'children' => $children, 'will_be_erased' => $all->isEmpty() && $children === [] && ! $history,
            'removal' => $all->isEmpty() && $children === [] && ! $history ? 'delete' : 'tombstone'];
    }

    private function hasReportingHistory(int $cid, OrgPosition $p): bool
    {
        $blockers = DB::table('org_reporting_history')->where('company_id', $cid)->where(function ($q) use ($p) {
            $q->where(fn ($w) => $w->where('position_id', $p->id)->where(fn ($x) => $x->whereNotNull('from_parent_id')->orWhereNull('to_parent_id')))
                ->orWhere('from_parent_id', $p->id)->orWhere('to_parent_id', $p->id);
        })->count() + max(0, DB::table('org_reporting_history')->where('company_id', $cid)->where('position_id', $p->id)->whereNull('from_parent_id')->count() - 1);

        return $blockers > 0;
    }

    /** Close one live assignment because its position is going away (history kept; not correctable, because there is nothing to reinstate into). */
    private function closeForRemoval(OrgPositionAssignment $a, User $actor, string $today, ?string $reason, string $cause = 'position_removed'): void
    {
        $before = $this->asnap($a);
        if ($a->starts_on->toDateString() > $today) {
            $a->forceFill(['status' => 'cancelled', 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
        } else {
            $newEnd = ($a->ends_on !== null && $a->ends_on->toDateString() < $today) ? $a->ends_on->toDateString() : $today;
            $a->forceFill(['status' => 'ended', 'ends_on' => $newEnd, 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
        }
        $this->auditOrFail('org.assignment.ended', $a, $before, $this->asnap($a) + ['cause' => $cause] + ($this->ownsAssignment($a, $actor) ? ['self_release' => true] : []), $actor, $reason);
    }

    /**
     * Dependency-aware removal (UAT-02). Never deletes an employee, user, department, title or permission and never cascades.
     *   - nothing depends on the position: it is erased (the audit keeps its snapshot), as before;
     *   - otherwise it is TOMBSTONED (status "removed"): hidden from the chart, lists and selectors and never assignable, while its assignments, reporting
     *     history and audit stay. Restore brings it back. This is different from archive (archived positions stay visible to managers and keep their place).
     *   - every current or scheduled assignment needs an explicit resolution: void (entered by mistake), end (legitimate, closes today) or move (to another
     *     position, same type). Valid history is never reclassified as a mistake.
     *   - child positions need an explicit choice: a new parent or the top level. No orphans, no silent deletion.
     * One transaction, tenant lock first. Any failure rolls everything back.
     *
     * @param  list<array<string, mixed>>  $resolutions  assignment_id, action (void|end|move), target_position_id? (move)
     * @param  array<string, mixed>  $children  parent_id (nullable: top level)
     * @return array<string, mixed>
     */
    public function deletePosition(User $actor, int $id, ?string $reason, mixed $confirm, array $resolutions = [], ?array $children = null): array
    {
        return DB::transaction(function () use ($actor, $id, $reason, $confirm, $resolutions, $children) {
            $actor = $this->begin($actor, 'organization.positions.manage');
            $reason = $this->reason($reason);
            $this->confirmed($confirm);
            $p = $this->position($actor, $id);
            $cid = $actor->company_id;
            $today = self::today($cid);
            $all = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $cid)->where('position_id', $p->id)->lockForUpdate()->get();
            foreach ($all->pluck('employee_id')->unique() as $eid) {
                $this->expireForEmployee($actor, (int) $eid);
            }
            $all = $all->each->refresh();
            $live = $all->filter(fn ($a) => $a->status === 'active' && ($a->ends_on === null || $a->ends_on->toDateString() >= $today));
            $kids = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->where('parent_id', $p->id)->where('status', '!=', 'removed')->orderBy('id')->lockForUpdate()->get();
            $history = $this->hasReportingHistory($cid, $p);
            $snap = $this->snap($p) + ['id' => $p->id, 'uuid' => $p->uuid, 'created_at' => (string) $p->created_at];

            if ($all->isEmpty() && $kids->isEmpty() && ! $history) {
                DB::table('org_reporting_history')->where('company_id', $cid)->where('position_id', $p->id)->delete();
                $this->auditOrFail('org.position.deleted', $p, $snap, null, $actor, $reason);
                $p->delete();

                return ['mode' => 'deleted', 'snapshot' => $snap, 'summary' => []];
            }

            // Resolutions: exactly one per live assignment, nothing else.
            $byId = [];
            foreach ($resolutions as $r) {
                $aid = (int) ($r['assignment_id'] ?? 0);
                if ($aid === 0 || isset($byId[$aid]) || ! in_array($r['action'] ?? null, ['void', 'end', 'move'], true) || ! $live->contains('id', $aid)) {
                    throw self::deny(422, 'resolution_invalid');
                }
                $byId[$aid] = $r;
            }
            $missing = $live->pluck('id')->diff(array_keys($byId))->values()->all();
            if ($missing !== []) {
                throw self::deny(422, 'resolution_required', ['missing' => $missing]);
            }
            if ($live->isNotEmpty() && ! PermissionResolver::has($actor, 'organization.assignments.manage')) {
                throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
            }
            // Children: one explicit choice for all direct children.
            $newParent = null;
            if ($kids->isNotEmpty()) {
                if ($children === null || ! array_key_exists('parent_id', $children)) {
                    throw self::deny(422, 'children_resolution_required', ['children' => $kids->pluck('id')->all()]);
                }
                if (! PermissionResolver::has($actor, 'organization.reporting.manage')) {
                    throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
                }
                $newParent = $children['parent_id'] === null ? null : (int) $children['parent_id'];
                if ($newParent !== null) {
                    if ($newParent === $p->id) {
                        throw self::deny(422, 'org_cycle');
                    }
                    $this->assertParentUsable($actor, $newParent);
                    $kidIds = $kids->pluck('id')->all();
                    $cursor = $newParent;
                    $seen = [];
                    while ($cursor !== null && ! isset($seen[$cursor])) {
                        if (in_array($cursor, $kidIds, true)) {
                            throw self::deny(422, 'org_cycle');
                        }
                        $seen[$cursor] = true;
                        $cursor = OrgPosition::withoutGlobalScopes()->where('company_id', $cid)->whereKey($cursor)->value('parent_id');
                    }
                }
                foreach ($kids as $k) {
                    if ($this->actorHolds($actor, $k->id)) {
                        throw self::deny(403, 'self_target');
                    }
                }
            }

            $summary = ['voided' => 0, 'ended' => 0, 'moved' => 0, 'children_reparented' => 0];
            foreach ($live as $a) {
                $r = $byId[$a->id];
                if ($r['action'] === 'void') {
                    $this->voidRow($a, $actor, $reason, 'org.assignment.voided', (string) Str::uuid());
                    $summary['voided']++;
                } elseif ($r['action'] === 'end') {
                    $this->closeForRemoval($a, $actor, $today, $reason);
                    $summary['ended']++;
                } else {
                    $target = (int) ($r['target_position_id'] ?? 0);
                    if ($target === 0 || $target === $p->id) {
                        throw self::deny(422, 'resolution_invalid');
                    }
                    $wasPlacement = (bool) $a->is_primary_placement;
                    $start = max($today, $a->starts_on->toDateString());
                    $end = $a->ends_on?->toDateString();
                    $type = $a->type;
                    $employeeId = $a->employee_id;
                    $this->closeForRemoval($a, $actor, $today, $reason);
                    $this->doAssign($actor, $target, ['employee_id' => $employeeId, 'type' => $type, 'starts_on' => $start, 'ends_on' => $end, 'is_primary_placement' => $wasPlacement], $reason);
                    $summary['moved']++;
                }
            }
            foreach ($kids as $k) {
                $fromParent = $k->parent_id;
                $k->forceFill(['parent_id' => $newParent, 'updated_by' => $actor->id])->save();
                DB::table('org_reporting_history')->insert([
                    'company_id' => $cid, 'position_id' => $k->id, 'from_parent_id' => $fromParent, 'to_parent_id' => $newParent,
                    'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
                ]);
                $this->auditOrFail('org.position.reparented', $k, ['parent_id' => $fromParent], ['parent_id' => $newParent, 'cause' => 'parent_removed', 'removed_position_id' => $p->id], $actor, $reason);
                $summary['children_reparented']++;
            }
            $p->forceFill(['status' => 'removed', 'removed_at' => now(), 'removed_by' => $actor->id, 'removed_reason' => $reason, 'updated_by' => $actor->id])->save();
            $this->auditOrFail('org.position.removed', $p, $snap, $this->snap($p) + $summary, $actor, $reason);

            return ['mode' => 'removed', 'snapshot' => $snap, 'summary' => $summary];
        });
    }

    public function archivePosition(User $actor, int $id, ?string $reason): array
    {
        return DB::transaction(function () use ($actor, $id, $reason) {
            $actor = $this->begin($actor, 'organization.positions.manage');
            $reason = $this->reason($reason);
            $p = $this->position($actor, $id);
            if ($p->isArchived()) {
                throw self::deny(409, 'position_archived');
            }
            if ($this->actorHolds($actor, $p->id)) {
                throw self::deny(403, 'self_target');
            }
            $children = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('parent_id', $p->id)->where('status', 'active')->count();
            if ($children > 0) {
                throw self::deny(409, 'has_active_children');
            }
            $before = $this->snap($p);
            $ended = $this->endAssignmentsWhere($p->id, $actor, 'position_archived', self::today($actor->company_id), $reason);
            $p->forceFill(['status' => 'archived', 'archived_at' => now(), 'updated_by' => $actor->id])->save();
            $this->auditOrFail('org.position.archived', $p, $before, $this->snap($p) + ['assignments_ended' => $ended], $actor, $reason);

            return ['position' => $p, 'assignments_ended' => $ended];
        });
    }

    public function restorePosition(User $actor, int $id, ?string $reason, bool $hasDepartment = false, ?int $departmentId = null): OrgPosition
    {
        return DB::transaction(function () use ($actor, $id, $reason, $hasDepartment, $departmentId) {
            $actor = $this->begin($actor, 'organization.positions.manage');
            $reason = $this->reason($reason);
            $p = $this->position($actor, $id, true);
            if (! $p->isArchived() && ! $p->isRemoved()) {
                throw self::deny(409, 'position_not_archived');
            }
            if ($p->parent_id !== null) {
                $parent = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($p->parent_id)->lockForUpdate()->first();
                if (! $parent || $parent->isArchived() || $parent->isRemoved()) {
                    throw self::deny(409, 'parent_archived');
                }
            }
            if ($p->department_id !== null && ! Department::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereNull('deleted_at')->whereKey($p->department_id)->lockForUpdate()->exists()) {
                // F11: the department this position belonged to was deleted. The administrator must pick a valid department (or none) explicitly.
                if (! $hasDepartment) {
                    throw self::deny(409, 'department_deleted', ['department_id' => $p->department_id]);
                }
                $this->assertDepartment($actor, $departmentId);
                $p->department_id = $departmentId;
            }
            $before = $this->snap($p);
            $p->forceFill(['status' => 'active', 'archived_at' => null, 'removed_at' => null, 'removed_by' => null, 'removed_reason' => null, 'updated_by' => $actor->id])->save();
            // Assignments ended by the archive are NOT restored.
            $this->auditOrFail('org.position.restored', $p, $before, $this->snap($p) + ['assignments_restored' => 0], $actor, $reason);

            return $p;
        });
    }

    public function reparent(User $actor, int $id, ?int $newParentId, ?string $reason, bool $hasExpected, ?int $expectedParentId): array
    {
        return DB::transaction(function () use ($actor, $id, $newParentId, $reason, $hasExpected, $expectedParentId) {
            $actor = $this->begin($actor, 'organization.reporting.manage');
            $reason = $this->reason($reason);
            $p = $this->position($actor, $id);
            if ($p->isArchived()) {
                throw self::deny(409, 'position_archived');
            }
            if ($hasExpected && $expectedParentId !== $p->parent_id) {
                throw self::deny(409, 'stale_parent');
            }
            if ($newParentId === $p->parent_id) {
                throw self::deny(422, 'parent_unchanged');
            }
            if ($this->actorHolds($actor, $p->id)) {
                throw self::deny(403, 'self_target');
            }
            if ($newParentId !== null) {
                if ($newParentId === $p->id) {
                    throw self::deny(422, 'org_cycle');
                }
                $this->assertParentUsable($actor, $newParentId);
                // Walk up from the proposed parent; reaching the moved position means a cycle. Safe under the tenant lock.
                $cursor = $newParentId;
                $seen = [];
                while ($cursor !== null && ! isset($seen[$cursor])) {
                    if ($cursor === $p->id) {
                        throw self::deny(422, 'org_cycle');
                    }
                    $seen[$cursor] = true;
                    $cursor = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($cursor)->value('parent_id');
                }
            }
            $before = $p->parent_id;
            $branch = $this->branchSize($actor->company_id, $p->id);
            $p->forceFill(['parent_id' => $newParentId, 'updated_by' => $actor->id])->save();
            DB::table('org_reporting_history')->insert([
                'company_id' => $actor->company_id, 'position_id' => $p->id, 'from_parent_id' => $before, 'to_parent_id' => $newParentId,
                'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now(),
            ]);
            $this->auditOrFail('org.position.reparented', $p, ['parent_id' => $before], ['parent_id' => $newParentId, 'branch_positions' => $branch], $actor, $reason);

            return ['position' => $p, 'branch_positions' => $branch];
        });
    }

    public function branchSize(int $companyId, int $rootId): int
    {
        $rows = OrgPosition::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->get(['id', 'parent_id']);
        $children = [];
        foreach ($rows as $r) {
            $children[$r->parent_id][] = $r->id;
        }
        $count = 0;
        $stack = [$rootId];
        $seen = [];
        while ($stack) {
            $c = array_pop($stack);
            if (isset($seen[$c])) {
                continue;
            }
            $seen[$c] = true;
            $count++;
            foreach ($children[$c] ?? [] as $k) {
                $stack[] = $k;
            }
        }

        return $count;
    }

    private function assertParentUsable(User $actor, int $parentId): void
    {
        $parent = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($parentId)->lockForUpdate()->first();
        if (! $parent) {
            throw self::deny(422, 'parent_invalid'); // missing or another tenant: indistinguishable
        }
        if ($parent->isRemoved()) {
            throw self::deny(422, 'parent_invalid');
        }
        if ($parent->isArchived()) {
            throw self::deny(422, 'parent_archived');
        }
    }

    private function assertDepartment(User $actor, $departmentId): void
    {
        if ($departmentId !== null && ! Department::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereNull('deleted_at')->whereKey($departmentId)->exists()) {
            throw ValidationException::withMessages(['department_id' => [__('organization.department_invalid')]]);
        }
    }

    private function assertCodeFree(User $actor, string $code, ?int $ignoreId = null): void
    {
        $q = OrgPosition::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('code', $code);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw self::deny(409, 'code_taken');
        }
    }

    // ───────────────────────── assignments ─────────────────────────

    /** @param array<string, mixed> $d */
    public function assign(User $actor, int $positionId, array $d): OrgPositionAssignment
    {
        return DB::transaction(function () use ($actor, $positionId, $d) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($d['reason'] ?? null);

            return $this->doAssign($actor, $positionId, $d, $reason);
        });
    }

    /**
     * Seat rules for one assignment window against the other non-cancelled assignments of the position (caller holds the tenant lock).
     * Single position: one primary at a time (unchanged). Multiple position: any number of different employees, capped by capacity on EVERY day of the window.
     * Acting stays one per position in both models.
     */
    private function assertSeat(OrgPosition $p, $base, string $type, string $start, ?string $end, ?User $actor = null): void
    {
        if ($type === 'primary' && $p->occupancy_type === 'multiple') {
            if ($p->capacity !== null && $this->peakConcurrency(clone $base, 'primary', $start, $end) + 1 > $p->capacity) {
                throw self::deny(409, 'capacity_full', ['capacity' => (int) $p->capacity] + $this->conflictMeta($p, $this->overlapping(clone $base, $start, $end)->where('type', 'primary')->get(), $actor));
            }

            return;
        }
        foreach (['primary' => 'primary_conflict', 'acting' => 'acting_conflict'] as $t => $code) {
            if ($type === $t) {
                $blockers = $this->overlapping(clone $base, $start, $end)->where('type', $t)->get();
                if ($blockers->isNotEmpty()) {
                    // F05: when every blocker is a CLOSED window (for example a holder who left with a future termination date) say when the seat frees up.
                    $meta = $blockers->every(fn ($r) => $r->ends_on !== null)
                        ? ['earliest_start' => Carbon::parse($blockers->max(fn ($r) => Carbon::parse($r->ends_on)->toDateString()))->addDay()->toDateString()] : [];
                    throw self::deny(409, $code, $meta + $this->conflictMeta($p, $blockers, $actor));
                }
            }
        }
    }

    /**
     * UAT-03/04: a structured description of what blocks an assignment, so the screen can name the real reason and offer the existing corrective
     * actions. Only actions the backend would accept are listed. Nothing is changed here. $rows are the blocking (non-cancelled, overlapping) assignments.
     *
     * @param  Collection<int, OrgPositionAssignment>  $rows
     * @return array<string, mixed>
     */
    private function conflictMeta(OrgPosition $p, $rows, ?User $actor): array
    {
        $today = self::today($p->company_id);
        $emps = Employee::withoutGlobalScopes()->where('company_id', $p->company_id)->whereIn('id', $rows->pluck('employee_id')->all())->get()->keyBy('id');
        $live = $this->live($this->nonCancelled($p->id), $today)->where('starts_on', '<=', $today)->where('type', 'primary')->count();
        $conflicts = $rows->map(function (OrgPositionAssignment $a) use ($emps, $actor, $today) {
            $e = $emps[$a->employee_id] ?? null;
            $own = $actor !== null && $e && $e->user_id !== null && $e->user_id === $actor->id;
            $cause = $this->effectiveCause($a, $today);
            $isLive = $a->status === 'active' && ($a->ends_on === null || $a->ends_on->toDateString() >= $today);
            $state = $a->status === 'cancelled' ? 'cancelled' : ($isLive ? ($a->starts_on->toDateString() > $today ? 'scheduled' : 'active') : 'ended');
            $actions = [];
            if ($isLive) {
                $actions[] = 'end';
                $actions[] = 'void';
                if (! $own) {
                    $actions[] = 'correct';
                }
            } elseif ($state === 'ended' && $this->correctableCause($cause)) {
                $actions[] = 'void';
                if (! $own && $e && ! $e->trashed() && $e->employment_status !== EmploymentStatus::Terminated) {
                    $actions[] = 'reinstate';
                }
            }

            return [
                'assignment_id' => $a->id, 'employee_id' => $a->employee_id, 'employee_name' => $e ? trim($e->first_name.' '.$e->last_name) : null, 'employee_number' => $e?->employee_number,
                'type' => $a->type, 'status' => $state, 'starts_on' => $a->starts_on->toDateString(), 'ends_on' => $a->ends_on?->toDateString(), 'end_cause' => $isLive ? null : $cause,
                'actions' => $actions,
            ];
        })->values()->all();

        return ['position_id' => $p->id, 'occupancy_type' => $p->occupancy_type, 'capacity' => $p->capacity, 'current_primary' => $live, 'conflicts' => $conflicts];
    }

    /** Highest number of simultaneous assignments of the type on any day of [start, end] (end null = open). */
    private function peakConcurrency($base, string $type, string $start, ?string $end): int
    {
        $rows = $this->overlapping($base, $start, $end)->where('type', $type)->get(['starts_on', 'ends_on']);
        $points = array_unique(array_merge([$start], $rows->map(fn ($r) => max($start, Carbon::parse($r->starts_on)->toDateString()))->all()));
        $peak = 0;
        foreach ($points as $d) {
            $n = $rows->filter(fn ($r) => Carbon::parse($r->starts_on)->toDateString() <= $d && ($r->ends_on === null || Carbon::parse($r->ends_on)->toDateString() >= $d))->count();
            $peak = max($peak, $n);
        }

        return $peak;
    }

    /**
     * The assignment rules, run inside the caller's transaction with the tenant lock already held and the actor already authorised.
     * Shared by assign() and replaceAssignment() so a replacement obeys exactly the same overlap, placement and eligibility rules.
     *
     * @param  array<string, mixed>  $d
     */
    private function doAssign(User $actor, int $positionId, array $d, ?string $reason, ?int $replacesId = null, ?string $correctionId = null): OrgPositionAssignment
    {

        $type = (string) $d['type'];
        $today = self::today($actor->company_id);
        $start = (string) ($d['starts_on'] ?? $today);
        $end = $d['ends_on'] ?? null;
        if ($end !== null && $end < $start) {
            throw ValidationException::withMessages(['ends_on' => [__('organization.dates_invalid')]]);
        }
        $employee = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey((int) $d['employee_id'])->lockForUpdate()->first();
        if (! $employee) {
            throw self::deny(404, 'not_found');
        }
        $this->expireForEmployee($actor, $employee->id);
        $p = $this->position($actor, $positionId);
        if ($p->isArchived()) {
            throw self::deny(409, 'position_archived');
        }
        if ($p->kind === 'body') {
            throw self::deny(422, 'body_not_assignable');
        }
        if ($employee->trashed() || $employee->employment_status === EmploymentStatus::Terminated) {
            throw self::deny(422, 'employee_not_eligible');
        }
        if ($employee->user_id !== null && $employee->user_id === $actor->id) {
            throw self::deny(403, 'self_target');
        }
        $base = $this->nonCancelled($p->id);
        if ($this->overlapping(clone $base, $start, $end)->where('employee_id', $employee->id)->exists()) {
            // Inclusive end dates: the earliest free day is the day after the latest end. An open-ended assignment has no such day.
            $rows = (clone $base)->where('employee_id', $employee->id)->get(['starts_on', 'ends_on']);
            $meta = [];
            if ($rows->isNotEmpty() && $rows->every(fn ($r) => $r->ends_on !== null)) {
                $meta['earliest_start'] = Carbon::parse($rows->max(fn ($r) => Carbon::parse($r->ends_on)->toDateString()))->addDay()->toDateString();
            }
            $same = $this->overlapping(clone $base, $start, $end)->where('employee_id', $employee->id)->get();
            throw self::deny(409, 'duplicate_assignment', $meta + $this->conflictMeta($p, $same, $actor));
        }
        $this->assertSeat($p, $base, $type, $start, $end, $actor);
        $wantsPlacement = (bool) ($d['is_primary_placement'] ?? false);
        if ($wantsPlacement && ($type === 'deputy')) {
            throw self::deny(422, 'placement_type_invalid');
        }
        // Placement is a date-window rule: only a flagged placement that overlaps THIS assignment's window is a clash. Consecutive placements are fine.
        if ($wantsPlacement && $this->placementClashes($actor->company_id, $employee->id, $start, $end)->exists()) {
            throw self::deny(409, 'placement_exists');
        }
        $anyLive = $this->live(OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('employee_id', $employee->id), $today)->exists();
        // Only a first, sole live assignment becomes the placement automatically; otherwise it stays explicit.
        $placement = $wantsPlacement || (! $anyLive && $type !== 'deputy');
        $a = OrgPositionAssignment::create([
            'position_id' => $p->id, 'employee_id' => $employee->id, 'type' => $type, 'starts_on' => $start, 'ends_on' => $end,
            'status' => 'active', 'is_primary_placement' => $placement, 'reason' => $reason, 'created_by' => $actor->id,
            'replaces_assignment_id' => $replacesId, 'correction_id' => $correctionId,
        ]);
        $this->auditOrFail('org.assignment.created', $a, null, $this->asnap($a) + ($replacesId ? ['replaces_assignment_id' => $replacesId, 'correction_id' => $correctionId] : []), $actor, $reason);

        return $a;

    }

    public function endAssignment(User $actor, int $id, ?string $endsOn, ?string $reason): OrgPositionAssignment
    {
        return DB::transaction(function () use ($actor, $id, $endsOn, $reason) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($reason);
            $a = $this->lockAssignment($actor, $id);
            $employee = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($a->employee_id)->lockForUpdate()->first();
            $this->expireForEmployee($actor, $a->employee_id);
            $a->refresh();
            if ($a->status !== 'active') {
                throw self::deny(409, 'assignment_not_active');
            }
            // F09 self-release: ending one's OWN placement only removes a holder, grants nothing, and is audited with self_release=true.
            $selfRelease = $employee && $employee->user_id !== null && $employee->user_id === $actor->id;
            $today = self::today($actor->company_id);
            if ($a->starts_on->toDateString() > $today && $endsOn === null) {
                // Scheduled but not yet started and no end date given: cancel it. History keeps the row; it never became effective.
                $before = $this->asnap($a);
                $a->forceFill(['status' => 'cancelled', 'is_primary_placement' => false, 'end_cause' => 'manual', 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
                $this->auditOrFail('org.assignment.ended', $a, $before, $this->asnap($a) + ['cause' => 'cancelled_before_start'] + ($selfRelease ? ['self_release' => true] : []), $actor, $reason);

                return $a;
            }
            $end = $endsOn ?? $today;
            if ($end < $a->starts_on->toDateString() || ($a->ends_on !== null && $end > $a->ends_on->toDateString())) {
                throw ValidationException::withMessages(['ends_on' => [__('organization.dates_invalid')]]);
            }
            $before = $this->asnap($a);
            $over = $end < $today || $end === $today;
            $a->forceFill([
                'ends_on' => $end, 'status' => $over ? 'ended' : 'active', 'is_primary_placement' => $over ? false : $a->is_primary_placement,
                'end_cause' => 'manual', 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now(),
            ])->save();
            $this->auditOrFail('org.assignment.ended', $a, $before, $this->asnap($a) + ($selfRelease ? ['self_release' => true] : []), $actor, $reason);

            return $a;
        });
    }

    public function setPlacement(User $actor, int $id, ?string $reason): OrgPositionAssignment
    {
        return DB::transaction(function () use ($actor, $id, $reason) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($reason);
            $a = $this->lockAssignment($actor, $id);
            $employee = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($a->employee_id)->lockForUpdate()->first();
            if ($employee) {
                $this->expireForEmployee($actor, $a->employee_id);
                $a->refresh();
            }
            if ($a->status !== 'active' || ! $employee || $employee->trashed() || $employee->employment_status === EmploymentStatus::Terminated) {
                throw self::deny(409, 'assignment_not_active');
            }
            if ($employee->user_id !== null && $employee->user_id === $actor->id) {
                throw self::deny(403, 'self_target');
            }
            if ($a->type === 'deputy') {
                throw self::deny(422, 'placement_type_invalid');
            }
            $a->loadMissing('position');
            if ($a->position->isArchived()) {
                throw self::deny(409, 'position_archived');
            }
            $today = self::today($actor->company_id);
            $clashes = $this->placementClashes($actor->company_id, $a->employee_id, $a->starts_on->toDateString(), $a->ends_on?->toDateString(), $a->id)->lockForUpdate()->get();
            if ($clashes->isNotEmpty() && $a->starts_on->toDateString() > $today) {
                // A future-effective placement never removes the placement that is in force now. End (or schedule the end of) the current one first.
                throw self::deny(409, 'placement_overlap');
            }
            foreach ($clashes as $previous) {
                $previous->forceFill(['is_primary_placement' => false])->save();
            }
            $previous = $clashes->first();
            $a->forceFill(['is_primary_placement' => true])->save();
            $this->auditOrFail('org.assignment.placement_set', $a, ['previous_assignment_id' => $previous?->id], ['assignment_id' => $a->id], $actor, $reason);

            return $a;
        });
    }

    // ───────────────────── administrative corrections (FINAL-V3) ─────────────────────
    //
    // Assignment dates stay DATE-ONLY and inclusive (see the V3 design note). A normal end date of today still means "held today", so the seat is not
    // free for a new holder until tomorrow (Option A, unchanged). Administrative corrections are separate, explicit operations that never delete
    // a row and never edit history silently:
    //   void       : the assignment should never have existed. Status becomes cancelled, cause "correction"; original dates, creator and reason are kept.
    //   reinstate  : an assignment ended (or voided) by mistake is restored; conflicts are re-checked like a new assignment.
    //   replace    : one atomic operation. mode "correction" voids the incumbent (entered in error); mode "handover" ends the incumbent the day before the
    //                effective date and starts the successor on the effective date (so there is never an overlap and never a waiting day).
    // All three need explicit confirmation and a reason of at least 10 characters, take the tenant lock, and write audit records.

    private function confirmed(mixed $confirm): void
    {
        if ($confirm !== true && $confirm !== 'true' && $confirm !== 1 && $confirm !== '1') {
            throw ValidationException::withMessages(['confirm' => [__('organization.confirmation_required')]]);
        }
    }

    /** Lock tenant, assignment and its employee; refuse self-targeting. */
    private function lockedForCorrection(User $actor, int $id, bool $allowSelfRelease = false): array
    {
        $a = $this->lockAssignment($actor, $id);
        $employee = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($a->employee_id)->lockForUpdate()->first();
        if (! $allowSelfRelease && $employee && $employee->user_id !== null && $employee->user_id === $actor->id) {
            throw self::deny(403, 'self_target');
        }

        return [$a, $employee];
    }

    /** True when the assignment's employee profile is the acting user's own. */
    private function ownsAssignment(OrgPositionAssignment $a, User $actor): bool
    {
        return Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($a->employee_id)->where('user_id', $actor->id)->exists();
    }

    private function voidRow(OrgPositionAssignment $a, User $actor, ?string $reason, string $action, ?string $correctionId, string $cause = 'correction'): void
    {
        $before = $this->asnap($a) + ['end_cause' => $a->end_cause];
        $a->forceFill([
            'status' => 'cancelled', 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id,
            'ended_at' => $a->ended_at ?? now(), 'voided_at' => now(), 'voided_by' => $actor->id, 'correction_id' => $correctionId ?? $a->correction_id,
        ])->save();
        $this->auditOrFail($action, $a, $before, $this->asnap($a) + ['cause' => $cause, 'correction_id' => $a->correction_id] + ($this->ownsAssignment($a, $actor) ? ['self_release' => true] : []), $actor, $reason);
    }

    /** Cause that really applies today: a row still stored as active whose end date has passed has expired, scheduler or not. */
    private function effectiveCause(OrgPositionAssignment $a, string $today): ?string
    {
        if ($a->status === 'active' && $a->ends_on !== null && $a->ends_on->toDateString() < $today) {
            return 'expired';
        }

        return $a->end_cause;
    }

    /** Only mistakes are correctable: a manual end, an earlier correction or a replacement. Expiry, termination, deletion and archival are lifecycle facts. */
    private function correctableCause(?string $cause): bool
    {
        return in_array($cause, ['manual', 'correction', 'replaced'], true);
    }

    public function voidAssignment(User $actor, int $id, ?string $reason, mixed $confirm): OrgPositionAssignment
    {
        return DB::transaction(function () use ($actor, $id, $reason, $confirm) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($reason);
            $this->confirmed($confirm);
            [$a] = $this->lockedForCorrection($actor, $id, true);
            if ($a->status === 'cancelled') {
                throw self::deny(409, 'assignment_not_active');
            }
            $cause = $this->effectiveCause($a, self::today($actor->company_id));
            // A live assignment (no end cause) can be voided. An ended one only when it was a mistake; expiry, employee termination and deletion cannot be turned into a correction.
            if ($cause !== null && ! $this->correctableCause($cause)) {
                throw self::deny(409, 'assignment_not_correctable', ['cause' => $cause]);
            }
            $this->voidRow($a, $actor, $reason, 'org.assignment.voided', (string) Str::uuid());

            return $a;
        });
    }

    public function reinstateAssignment(User $actor, int $id, ?string $endsOn, ?string $reason, mixed $confirm, bool $restorePlacement = false): OrgPositionAssignment
    {
        return DB::transaction(function () use ($actor, $id, $endsOn, $reason, $confirm, $restorePlacement) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($reason);
            $this->confirmed($confirm);
            [$a, $employee] = $this->lockedForCorrection($actor, $id);
            $today = self::today($actor->company_id);
            if ($a->status === 'active' && ($a->ends_on === null || $a->ends_on->toDateString() >= $today)) {
                throw self::deny(409, 'assignment_not_ended');
            }
            // Same answer before and after the expiry scheduler: an end date in the past is natural expiry whatever the stored status says.
            $cause = $this->effectiveCause($a, $today);
            if (! $employee || $employee->trashed() || $employee->employment_status === EmploymentStatus::Terminated) {
                throw self::deny(422, 'employee_not_eligible');
            }
            // Natural expiry or an employee leaving is not a mistake: only a manual end, an earlier correction or a replacement can be undone.
            if (! $this->correctableCause($cause)) {
                throw self::deny(409, 'assignment_not_reinstatable', ['cause' => $cause]);
            }
            $position = $this->position($actor, $a->position_id);
            if ($position->isArchived()) {
                throw self::deny(409, 'position_archived');
            }
            $start = $a->starts_on->toDateString();
            $end = $endsOn;
            if ($end !== null && $end < max($start, $today)) {
                throw ValidationException::withMessages(['ends_on' => [__('organization.dates_invalid')]]);
            }
            $others = $this->nonCancelled($a->position_id)->where('id', '!=', $a->id);
            if ($this->overlapping(clone $others, $start, $end)->where('employee_id', $a->employee_id)->exists()) {
                throw self::deny(409, 'duplicate_assignment');
            }
            $this->assertSeat($position, $others, $a->type, $start, $end, $actor);
            // The placement flag is never restored silently. It comes back only when asked for and only if no other placement overlaps.
            if ($restorePlacement && $this->placementClashes($a->company_id, $a->employee_id, $start, $end, $a->id)->exists()) {
                throw self::deny(409, 'placement_conflict');
            }
            $before = $this->asnap($a) + ['end_cause' => $a->end_cause];
            $a->forceFill([
                'status' => 'active', 'ends_on' => $end, 'end_cause' => null, 'ended_at' => null, 'ended_by' => null, 'end_reason' => null,
                'voided_at' => null, 'voided_by' => null, 'is_primary_placement' => $restorePlacement,
            ])->save();
            $this->auditOrFail('org.assignment.reinstated', $a, $before, $this->asnap($a) + ['restored_end_cause' => $before['end_cause']], $actor, $reason);

            return $a;
        });
    }

    /**
     * Atomic incumbent replacement. Either everything below commits or nothing does.
     *
     * @param  array<string, mixed>  $d  employee_id, mode (correction|handover), effective_on?, is_primary_placement?, reason, confirm
     * @return array{old: OrgPositionAssignment, new: OrgPositionAssignment}
     */
    public function replaceAssignment(User $actor, int $id, array $d): array
    {
        return DB::transaction(function () use ($actor, $id, $d) {
            $actor = $this->begin($actor, 'organization.assignments.manage');
            $reason = $this->reason($d['reason'] ?? null);
            $this->confirmed($d['confirm'] ?? null);
            $mode = (string) ($d['mode'] ?? '');
            if (! in_array($mode, ['correction', 'handover'], true)) {
                throw ValidationException::withMessages(['mode' => [__('organization.mode_invalid')]]);
            }
            $today = self::today($actor->company_id);
            $effective = (string) ($d['effective_on'] ?? $today);
            if ($effective < $today) {
                throw ValidationException::withMessages(['effective_on' => [__('organization.dates_invalid')]]);
            }
            [$old] = $this->lockedForCorrection($actor, $id, true);
            $newEmployee = Employee::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey((int) $d['employee_id'])->lockForUpdate()->first();
            if (! $newEmployee) {
                throw self::deny(404, 'not_found');
            }
            if ($old->status === 'cancelled' || ($old->ends_on !== null && $old->ends_on->toDateString() < $today)) {
                throw self::deny(409, 'assignment_not_active');
            }
            // Same lifecycle protection as void and reinstate: termination, deletion, archival and expiry cannot be overwritten by a correction or a handover.
            $oldCause = $this->effectiveCause($old, $today);
            if ($oldCause !== null && ! $this->correctableCause($oldCause)) {
                throw self::deny(409, 'assignment_not_correctable', ['cause' => $oldCause]);
            }
            if ($old->employee_id === $newEmployee->id) {
                throw self::deny(422, 'same_employee');
            }
            $this->expireForEmployee($actor, $newEmployee->id);
            $correction = (string) Str::uuid();
            if ($mode === 'correction') {
                $this->voidRow($old, $actor, $reason, 'org.assignment.voided', $correction);
            } else {
                $lastDay = Carbon::parse($effective)->subDay()->toDateString();
                if ($old->starts_on->toDateString() > $lastDay) {
                    // The incumbent would never have been in force before the successor: nothing happened to record, so the row is cancelled (kept).
                    $this->voidRow($old, $actor, $reason, 'org.assignment.ended', $correction, 'replaced');
                } else {
                    $newEnd = ($old->ends_on !== null && $old->ends_on->toDateString() < $lastDay) ? $old->ends_on->toDateString() : $lastDay;
                    $before = $this->asnap($old);
                    $over = $newEnd <= $today;
                    $old->forceFill([
                        'ends_on' => $newEnd, 'status' => $over ? 'ended' : 'active', 'is_primary_placement' => $over ? false : $old->is_primary_placement,
                        'end_cause' => 'replaced', 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now(), 'correction_id' => $correction,
                    ])->save();
                    $this->auditOrFail('org.assignment.ended', $old, $before, $this->asnap($old) + ['cause' => 'replaced', 'correction_id' => $correction] + ($this->ownsAssignment($old, $actor) ? ['self_release' => true] : []), $actor, $reason);
                }
            }
            $new = $this->doAssign($actor, $old->position_id, [
                'employee_id' => $newEmployee->id, 'type' => $old->type, 'starts_on' => $effective, 'ends_on' => null,
                'is_primary_placement' => $d['is_primary_placement'] ?? false,
            ], $reason, $old->id, $correction);
            $this->auditOrFail('org.assignment.replaced', $old, ['assignment_id' => $old->id, 'employee_id' => $old->employee_id], [
                'new_assignment_id' => $new->id, 'new_employee_id' => $new->employee_id, 'mode' => $mode, 'effective_on' => $effective, 'correction_id' => $correction,
            ] + ($this->ownsAssignment($old, $actor) ? ['self_release' => true] : []), $actor, $reason);

            return ['old' => $old->refresh(), 'new' => $new->refresh()];
        });
    }

    private function lockAssignment(User $actor, int $id): OrgPositionAssignment
    {
        $a = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($id)->lockForUpdate()->first();
        if (! $a) {
            throw self::deny(404, 'not_found');
        }

        return $a;
    }

    private function asnap(OrgPositionAssignment $a): array
    {
        return $a->only(['position_id', 'employee_id', 'type', 'status', 'is_primary_placement']) + [
            'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
        ];
    }

    // ───────────────────────── lifecycle integration ─────────────────────────

    /**
     * Called by AccountService inside ITS transaction (tenant, employee already locked) when employment ends or the
     * employee record is deleted. Ends or cancels the employee's live assignments; history is kept; the position stays
     * (and shows as vacant). No replacement is appointed and nothing is restored on reactivation.
     */
    public function endForEmployee(User $actor, Employee $employee, string $cause, string $date, ?string $reason): int
    {
        $rows = OrgPositionAssignment::withoutGlobalScopes()->where('company_id', $employee->company_id)->where('employee_id', $employee->id)
            ->where('status', 'active')->lockForUpdate()->get();
        $n = 0;
        foreach ($rows as $a) {
            $before = $this->asnap($a);
            if ($a->starts_on->toDateString() > $date) {
                $a->forceFill(['status' => 'cancelled', 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
            } else {
                $newEnd = ($a->ends_on !== null && $a->ends_on->toDateString() < $date) ? $a->ends_on->toDateString() : $date;
                $a->forceFill(['status' => 'ended', 'ends_on' => $newEnd, 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
            }
            $this->auditOrFail('org.assignment.ended', $a, $before, $this->asnap($a) + ['cause' => $cause], $actor, $reason);
            $n++;
        }

        return $n;
    }

    private function endAssignmentsWhere(int $positionId, User $actor, string $cause, string $date, ?string $reason): int
    {
        $rows = OrgPositionAssignment::withoutGlobalScopes()->where('position_id', $positionId)->where('status', 'active')->lockForUpdate()->get();
        foreach ($rows as $a) {
            $before = $this->asnap($a);
            if ($a->starts_on->toDateString() > $date) {
                $a->forceFill(['status' => 'cancelled', 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
            } else {
                $newEnd = ($a->ends_on !== null && $a->ends_on->toDateString() < $date) ? $a->ends_on->toDateString() : $date;
                $a->forceFill(['status' => 'ended', 'ends_on' => $newEnd, 'is_primary_placement' => false, 'end_cause' => $cause, 'end_reason' => $reason, 'ended_by' => $actor->id, 'ended_at' => now()])->save();
            }
            $this->auditOrFail('org.assignment.ended', $a, $before, $this->asnap($a) + ['cause' => $cause], $actor, $reason);
        }

        return $rows->count();
    }
}
