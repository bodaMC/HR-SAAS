<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** READ-ONLY Phase 3.4 report. Creates no positions or assignments and changes no reporting data. */
class OrgPreflight extends Command
{
    protected $signature = 'org:preflight {--json : Machine-readable output} {--company= : Company id}';

    protected $description = 'Phase 3.4 read-only preflight: reports legacy reporting data next to the (empty) organizational structure. Changes nothing.';

    public function handle(): int
    {
        $co = $this->option('company');
        $c = fn ($q, string $col) => $co ? $q->where($col, (int) $co) : $q;
        $emp = fn () => $c(DB::table('employees')->whereNull('deleted_at')->where('employment_status', '!=', 'terminated'), 'company_id');
        $out = [
            'employees_current' => $emp()->count(),
            'employees_with_direct_manager_legacy' => $emp()->whereNotNull('manager_id')->count(),
            'employees_with_team_leader_legacy' => $emp()->whereNotNull('team_leader_id')->count(),
            'employees_flagged_hod_ceo_team_leader_legacy' => $emp()->where(fn ($q) => $q->where('is_hod', true)->orWhere('is_ceo', true)->orWhere('is_team_leader', true))->count(),
            'departments_with_hod_legacy' => $c(DB::table('departments')->whereNull('deleted_at')->whereNotNull('hod_employee_id'), 'company_id')->count(),
            'companies_with_ceo_legacy' => $c(DB::table('companies')->whereNotNull('ceo_employee_id'), 'id')->count(),
            'org_positions' => $c(DB::table('org_positions'), 'company_id')->count(),
            'org_assignments' => $c(DB::table('org_position_assignments'), 'company_id')->count(),
            'terminated_employees_with_live_assignment' => $c(DB::table('org_position_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')
                ->where('a.status', 'active')->where(fn ($q) => $q->where('e.employment_status', 'terminated')->orWhereNotNull('e.deleted_at')), 'a.company_id')->count(),
        ];
        $notes = [
            'The legacy direct manager (manager_id) and flags keep driving leave, permission and approval scoping. They are never read or changed by the organization engine.',
            'No position or assignment is created for existing employees. Administrators configure the structure in the application.',
        ];
        if ($this->option('json')) {
            $this->line(json_encode(['report' => $out, 'notes' => $notes], JSON_PRETTY_PRINT));
        } else {
            foreach ($out as $k => $v) {
                $this->line(str_pad($k, 52).$v);
            }
            foreach ($notes as $n) {
                $this->line('- '.$n);
            }
        }

        return $out['terminated_employees_with_live_assignment'] > 0 ? 1 : 0;
    }
}
