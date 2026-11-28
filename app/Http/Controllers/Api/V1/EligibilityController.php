<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Organization\EmployeeEligibility;
use App\Rbac\PermissionResolver;
use App\Rbac\RbacDenied;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** FINAL-V3 read models and the explicit manager-scope configuration. Tenant comes from the authenticated user, never from input. */
class EligibilityController extends Controller
{
    public function __construct(private EmployeeEligibility $elig, private AuditLogger $audit) {}

    private function cid(Request $r, string ...$any): int
    {
        $u = $r->user();
        if ($u === null || $u->company_id === null) {
            throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
        }
        foreach ($any as $perm) {
            if (PermissionResolver::has($u, $perm)) {
                return (int) $u->company_id;
            }
        }
        throw new RbacDenied(403, 'forbidden', 'You do not have permission to perform this action.');
    }

    private function dept(int $cid, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (! DB::table('departments')->where('company_id', $cid)->whereNull('deleted_at')->where('id', (int) $id)->exists()) {
            throw ValidationException::withMessages(['department_id' => [__('organization.department_invalid')]]);
        }

        return (int) $id;
    }

    public function managerCandidates(Request $r): JsonResponse
    {
        $cid = $this->cid($r, 'employees.create', 'employees.update');
        $d = $this->dept($cid, $r->query('department_id'));
        $ex = $r->query('employee_id') !== null ? (int) $r->query('employee_id') : null;

        $rows = $this->elig->candidates($cid, $d, $ex, $r->query('q'), 101);

        // One extra row is fetched only to tell the client the list is cut at 100: it should narrow the search instead of assuming the list is complete.
        return response()->json(['data' => array_slice($rows, 0, 100), 'more' => count($rows) > 100]);
    }

    public function jobTitlesFor(Request $r): JsonResponse
    {
        $cid = $this->cid($r, 'employees.create', 'employees.update', 'job_titles.view');

        return response()->json(['data' => $this->elig->titlesFor($cid, $this->dept($cid, $r->query('department_id')))]);
    }

    public function jobTitleReport(Request $r): JsonResponse
    {
        $cid = $this->cid($r, 'job_titles.view');
        $rows = $this->elig->jobTitleReport($cid);

        return response()->json(['data' => $rows, 'count' => count($rows)]);
    }

    public function managerReport(Request $r): JsonResponse
    {
        $cid = $this->cid($r, 'employees.view');
        $rows = $this->elig->managerReport($cid);

        return response()->json(['data' => $rows, 'count' => count($rows)]);
    }

    private function scopeOf(int $employeeId): array
    {
        $rows = DB::table('manager_department_scopes')->where('employee_id', $employeeId)->get();

        return $rows->contains('scope', 'all') ? ['scope' => 'all', 'department_ids' => []]
            : ($rows->contains('scope', 'own') ? ['scope' => 'own', 'department_ids' => []]
            : ($rows->isEmpty() ? ['scope' => 'none', 'department_ids' => []] : ['scope' => 'departments', 'department_ids' => $rows->pluck('department_id')->map(fn ($x) => (int) $x)->sort()->values()->all()]));
    }

    /** Read only: which legacy leadership settings independently make this employee eligible (they are never changed by the explicit configuration). */
    private function legacyBasis(int $cid, Employee $e): array
    {
        $isHead = DB::table('departments')->where('company_id', $cid)->where('hod_employee_id', $e->id)->whereNull('deleted_at')->exists();
        $isCeo = (bool) $e->is_ceo || DB::table('companies')->where('id', $cid)->where('ceo_employee_id', $e->id)->exists();

        return ['is_hod' => (bool) $e->is_hod, 'is_team_leader' => (bool) $e->is_team_leader, 'designated_department_head' => $isHead, 'ceo' => $isCeo,
            'department_id' => $e->department_id, 'any' => (bool) $e->is_hod || (bool) $e->is_team_leader || $isHead || $isCeo];
    }

    public function showScope(Request $r, int $employee): JsonResponse
    {
        $cid = $this->cid($r, 'employees.view');
        $e = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereKey($employee)->whereNull('deleted_at')->first();
        if (! $e) {
            throw new RbacDenied(404, 'not_found', 'Not found.');
        }

        return response()->json(['data' => $this->scopeOf($e->id) + ['legacy' => $this->legacyBasis($cid, $e)]]);
    }

    public function setScope(Request $r, int $employee): JsonResponse
    {
        $cid = $this->cid($r, 'employees.update');
        $d = $r->validate([
            'scope' => ['required', 'in:none,own,all,departments'], 'department_ids' => ['nullable', 'array', 'max:200'], 'department_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $d['department_ids'] ?? [])));
        if ($d['scope'] === 'departments' && $ids === []) {
            throw ValidationException::withMessages(['department_ids' => [__('organization.department_invalid')]]);
        }
        $out = DB::transaction(function () use ($r, $cid, $employee, $d, $ids) {
            Company::withoutGlobalScopes()->whereKey($cid)->lockForUpdate()->first(); // tenant lock first
            $e = Employee::withoutGlobalScopes()->where('company_id', $cid)->whereKey($employee)->lockForUpdate()->first();
            if (! $e || $e->trashed()) {
                throw new RbacDenied(404, 'not_found', 'Not found.');
            }
            if ($e->user_id !== null && $e->user_id === $r->user()->id) {
                throw new RbacDenied(403, 'self_target', __('organization.self_target'));
            }
            // A terminated employee cannot be given new managerial eligibility (removing it is always allowed).
            if ($d['scope'] !== 'none' && $e->employment_status->value === 'terminated') {
                throw ValidationException::withMessages(['scope' => [__('organization.manager_terminated')]]);
            }
            if ($d['scope'] === 'departments') {
                $ok = DB::table('departments')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('id', $ids)->count();
                if ($ok !== count($ids)) {
                    throw ValidationException::withMessages(['department_ids' => [__('organization.department_invalid')]]);
                }
            }
            $before = $this->scopeOf($e->id);
            DB::table('manager_department_scopes')->where('employee_id', $e->id)->delete();
            $now = now();
            if ($d['scope'] === 'own') {
                DB::table('manager_department_scopes')->insert(['company_id' => $cid, 'employee_id' => $e->id, 'scope' => 'own', 'department_id' => null, 'created_by' => $r->user()->id, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($d['scope'] === 'all') {
                DB::table('manager_department_scopes')->insert(['company_id' => $cid, 'employee_id' => $e->id, 'scope' => 'all', 'department_id' => null, 'created_by' => $r->user()->id, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($d['scope'] === 'departments') {
                foreach ($ids as $i) {
                    DB::table('manager_department_scopes')->insert(['company_id' => $cid, 'employee_id' => $e->id, 'scope' => 'department', 'department_id' => $i, 'created_by' => $r->user()->id, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $after = $this->scopeOf($e->id);
            if ($this->audit->log('employee.manager_scope_changed', $e, $before, $after, $r->user(), isset($d['reason']) && trim((string) $d['reason']) !== '' ? trim($d['reason']) : null) === null) {
                throw new \RuntimeException('Audit record could not be written; the change was rolled back.');
            }

            return $after;
        });

        return response()->json(['data' => $out]);
    }
}
