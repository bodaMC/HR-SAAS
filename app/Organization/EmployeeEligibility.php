<?php

namespace App\Organization;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FINAL-V3 department-aware eligibility for an employee's job title and direct manager. Enforced on the server (the UI filter is only a convenience).
 *
 * Direct manager candidates for department D (never written to, never synchronised with, the legacy approval flags):
 *   1. an employee of D flagged is_hod / is_team_leader, or the department's designated head (departments.hod_employee_id)  [legacy flags, read only]
 *   2. the company's designated CEO (employees.is_ceo or companies.ceo_employee_id) in every department
 *   3. an employee with an explicit scope row in manager_department_scopes covering D, all departments, or 'own' (their own department)  [the explicit configuration]
 * Job title availability: 'all' (default for every existing title) or 'selected' departments in job_title_departments.
 */
class EmployeeEligibility
{
    /** Base query of employees that could ever be a manager: tenant, not deleted, not terminated. */
    private function pool(int $companyId)
    {
        return Employee::withoutGlobalScopes()->where('employees.company_id', $companyId)->whereNull('employees.deleted_at')
            ->where('employees.employment_status', '!=', 'terminated');
    }

    /** Adds the eligibility predicate for the selected department (null = no department). */
    private function eligible($q, int $companyId, ?int $deptId)
    {
        return $q->where(function ($w) use ($companyId, $deptId) {
            if ($deptId !== null) {
                $w->where(fn ($x) => $x->where('employees.department_id', $deptId)->where(fn ($y) => $y->where('employees.is_hod', true)->orWhere('employees.is_team_leader', true)))
                    ->orWhereIn('employees.id', DB::table('departments')->where('company_id', $companyId)->where('id', $deptId)->whereNotNull('hod_employee_id')->select('hod_employee_id'))
                    ->orWhereIn('employees.id', DB::table('manager_department_scopes')->where('company_id', $companyId)->where('scope', 'department')->where('department_id', $deptId)->select('employee_id'))
                    // UAT-01: "own department only" is an explicit setting that follows the employee's current department.
                    ->orWhere(fn ($x) => $x->where('employees.department_id', $deptId)->whereIn('employees.id', DB::table('manager_department_scopes')->where('company_id', $companyId)->where('scope', 'own')->select('employee_id')));
            }
            $w->orWhere('employees.is_ceo', true)
                ->orWhereIn('employees.id', DB::table('companies')->where('id', $companyId)->whereNotNull('ceo_employee_id')->select('ceo_employee_id'))
                ->orWhereIn('employees.id', DB::table('manager_department_scopes')->where('company_id', $companyId)->where('scope', 'all')->select('employee_id'));
        });
    }

    /** Why this employee qualifies for the department: list of codes. */
    public function basis(int $companyId, Employee $e, ?int $deptId): array
    {
        $b = [];
        if ($deptId !== null && $e->department_id === $deptId && ($e->is_hod || $e->is_team_leader)) {
            $b[] = $e->is_hod ? 'department_head' : 'team_leader';
        }
        if ($deptId !== null && DB::table('departments')->where('company_id', $companyId)->where('id', $deptId)->where('hod_employee_id', $e->id)->exists()) {
            $b[] = 'department_head';
        }
        if ($e->is_ceo || DB::table('companies')->where('id', $companyId)->where('ceo_employee_id', $e->id)->exists()) {
            $b[] = 'ceo';
        }
        $scopes = DB::table('manager_department_scopes')->where('employee_id', $e->id)->get();
        if ($scopes->contains('scope', 'all') || ($deptId !== null && $scopes->where('scope', 'department')->contains('department_id', $deptId))) {
            $b[] = 'explicit_scope';
        }
        if ($deptId !== null && $e->department_id === $deptId && $scopes->contains('scope', 'own')) {
            $b[] = 'explicit_own_department';
        }

        return array_values(array_unique($b));
    }

    /** Candidate list for the form. $excludeId removes the employee itself and everyone below it (cycle prevention). */
    public function candidates(int $companyId, ?int $deptId, ?int $excludeId, ?string $search, int $limit = 100): array
    {
        $q = $this->eligible($this->pool($companyId), $companyId, $deptId);
        if ($excludeId !== null) {
            $banned = array_merge([$excludeId], $this->subordinateIds($companyId, $excludeId));
            $q->whereNotIn('employees.id', $banned);
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.mb_strtolower(addcslashes($search, '\\%_')).'%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(employees.first_name || ' ' || employees.last_name) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(employees.employee_number) LIKE ? ESCAPE '\\'", [$like]));
        }
        $rows = $q->orderBy('employees.first_name')->orderBy('employees.id')->limit($limit)->get();

        return $rows->map(fn (Employee $e) => ['id' => $e->id, 'name' => trim($e->first_name.' '.$e->last_name), 'employee_number' => $e->employee_number, 'department_id' => $e->department_id, 'basis' => $this->basis($companyId, $e, $deptId)])->all();
    }

    /** @return list<int> everyone who reports (directly or not) to the employee, via manager_id. */
    public function subordinateIds(int $companyId, int $employeeId): array
    {
        $seen = [];
        $frontier = [$employeeId];
        while ($frontier) {
            $next = Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('manager_id', $frontier)->pluck('id')->all();
            $frontier = array_values(array_diff($next, $seen, [$employeeId]));
            $seen = array_merge($seen, $frontier);
        }

        return $seen;
    }

    /**
     * Server-side check, called inside the caller's transaction. Locks the manager row (shared) so a concurrent scope change waits.
     *
     * @throws ValidationException
     */
    public function assertManager(int $companyId, ?int $employeeId, ?int $managerId, ?int $deptId): void
    {
        if ($managerId === null) {
            return;
        }
        $m = Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($managerId)->sharedLock()->first();
        $fail = fn (string $key) => throw ValidationException::withMessages(['manager_id' => [__("organization.$key")]]);
        if (! $m || $m->trashed()) {
            $fail('manager_invalid');
        }
        if ($m->employment_status->value === 'terminated') {
            $fail('manager_terminated');
        }
        if ($employeeId !== null && ($managerId === $employeeId || in_array($managerId, $this->subordinateIds($companyId, $employeeId), true))) {
            $fail('manager_cycle');
        }
        if (! $this->eligible($this->pool($companyId), $companyId, $deptId)->whereKey($managerId)->exists()) {
            $fail('manager_not_eligible');
        }
    }

    /** @throws ValidationException */
    public function assertJobTitle(int $companyId, ?int $jobTitleId, ?int $deptId): void
    {
        if ($jobTitleId === null) {
            return;
        }
        $t = DB::table('job_titles')->where('company_id', $companyId)->where('id', $jobTitleId)->sharedLock()->first();
        $fail = fn (string $key) => throw ValidationException::withMessages(['job_title_id' => [__("organization.$key")]]);
        if (! $t || $t->deleted_at !== null) {
            $fail('job_title_invalid');
        }
        if (! $t->is_active) {
            $fail('job_title_inactive');
        }
        if ($t->department_availability === 'selected' && ($deptId === null || ! DB::table('job_title_departments')->where('job_title_id', $t->id)->where('department_id', $deptId)->exists())) {
            $fail('job_title_not_available');
        }
    }

    /** Titles selectable for a department: active, not deleted, available to all or to this department. */
    public function titlesFor(int $companyId, ?int $deptId): array
    {
        $q = DB::table('job_titles')->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->where(function ($w) use ($deptId) {
                $w->where('department_availability', 'all');
                if ($deptId !== null) {
                    $w->orWhereIn('id', DB::table('job_title_departments')->where('department_id', $deptId)->select('job_title_id'));
                }
            });

        return $q->orderBy('name')->get(['id', 'name', 'department_availability'])->all();
    }

    /** Read-only: employees whose current title is not available to their department. */
    public function jobTitleReport(int $companyId): array
    {
        $rows = DB::table('employees as e')->join('job_titles as t', function ($j) {
            $j->on('t.id', '=', 'e.job_title_id')->on('t.company_id', '=', 'e.company_id');
        })->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.company_id', $companyId)->whereNull('e.deleted_at')->where('e.employment_status', '!=', 'terminated')
            ->where(function ($w) {
                $w->where(fn ($x) => $x->where('t.department_availability', 'selected')->where(fn ($y) => $y->whereNull('e.department_id')->orWhereNotExists(fn ($z) => $z->select(DB::raw(1))->from('job_title_departments as j')->whereColumn('j.job_title_id', 't.id')->whereColumn('j.department_id', 'e.department_id'))))
                    ->orWhere(fn ($x) => $x->where('t.is_active', false)->orWhereNotNull('t.deleted_at'));
            })->orderBy('e.id')->limit(500)
            ->get(['e.id', 'e.employee_number', 'e.first_name', 'e.last_name', 'e.department_id', 'd.name as department', 't.id as job_title_id', 't.name as job_title', 't.is_active', 't.deleted_at']);

        return $rows->map(fn ($r) => ['employee_id' => $r->id, 'employee_number' => $r->employee_number, 'name' => trim($r->first_name.' '.$r->last_name), 'department_id' => $r->department_id, 'department' => $r->department,
            'job_title_id' => $r->job_title_id, 'job_title' => $r->job_title, 'reason' => ($r->is_active && $r->deleted_at === null) ? 'not_available_in_department' : 'job_title_inactive'])->all();
    }

    /** Read-only: employees whose current manager is no longer eligible for their department (or is gone). */
    public function managerReport(int $companyId): array
    {
        $out = [];
        $emps = Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('deleted_at')->where('employment_status', '!=', 'terminated')->whereNotNull('manager_id')->orderBy('id')->limit(2000)->get();
        foreach ($emps as $e) {
            $ok = $this->eligible($this->pool($companyId), $companyId, $e->department_id)->whereKey($e->manager_id)->exists();
            if (! $ok) {
                $m = Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($e->manager_id)->first();
                $out[] = ['employee_id' => $e->id, 'employee_number' => $e->employee_number, 'name' => trim($e->first_name.' '.$e->last_name), 'department_id' => $e->department_id,
                    'manager_id' => $e->manager_id, 'manager' => $m ? trim($m->first_name.' '.$m->last_name) : null, 'reason' => ($m === null || $m->trashed() || $m->employment_status->value === 'terminated') ? 'manager_unavailable' : 'manager_not_eligible_for_department'];
            }
        }

        return array_slice($out, 0, 500);
    }
}
