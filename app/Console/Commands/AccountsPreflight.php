<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** READ-ONLY report of employee/account inconsistencies. Never modifies data (Phase 3.3 section 18). */
class AccountsPreflight extends Command
{
    protected $signature = 'accounts:preflight {--json : Machine-readable output} {--company= : Company id}';

    protected $description = 'Phase 3.3 read-only preflight: reports inconsistent employee/account data. Changes nothing.';

    public function handle(): int
    {
        $co = $this->option('company');
        $scope = fn ($q, string $col) => $co ? $q->where($col, (int) $co) : $q;
        $checks = [
            'active_account_linked_to_terminated_employee' => $scope(DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.employment_status', 'terminated')->where('u.is_active', true)->whereNull('u.deleted_at'), 'e.company_id')
                ->get(['e.company_id', 'e.id as employee_id', 'u.id as user_id']),
            'active_account_linked_to_soft_deleted_employee' => $scope(DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->whereNotNull('e.deleted_at')->where('u.is_active', true)->whereNull('u.deleted_at'), 'e.company_id')
                ->get(['e.company_id', 'e.id as employee_id', 'u.id as user_id']),
            'account_linked_to_more_than_one_employee' => $scope(DB::table('employees')->whereNotNull('user_id')->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1'), 'company_id')->get([DB::raw('MIN(company_id) as company_id'), 'user_id', DB::raw('COUNT(*) as employees')]),
            'employee_linked_to_account_of_another_company' => $scope(DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->whereColumn('u.company_id', '!=', 'e.company_id'), 'e.company_id')->get(['e.company_id', 'e.id as employee_id', 'u.id as user_id']),
            'employee_linked_to_soft_deleted_account' => $scope(DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->whereNotNull('u.deleted_at'), 'e.company_id')->get(['e.company_id', 'e.id as employee_id', 'u.id as user_id']),
            'inactive_account_linked_to_active_employee' => $scope(DB::table('employees as e')->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('u.is_active', false)->whereNull('u.deleted_at')->where('e.employment_status', '!=', 'terminated')->whereNull('e.deleted_at'), 'e.company_id')
                ->get(['e.company_id', 'e.id as employee_id', 'u.id as user_id']),
            'tenant_account_without_employee_profile' => $scope(DB::table('users as u')->whereNull('u.deleted_at')->where(fn ($q) => $q->where('u.is_super_admin', false)->orWhereNull('u.is_super_admin'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('employees as e')->whereColumn('e.user_id', 'u.id')), 'u.company_id')
                ->get(['u.company_id', 'u.id as user_id']),
            'legacy_owner_admin_accounts' => $scope(DB::table('users as u')->whereNull('u.deleted_at')->whereIn('u.role', ['owner', 'admin']), 'u.company_id')->get(['u.company_id', 'u.id as user_id', 'u.role']),
        ];
        $out = [];
        foreach ($checks as $name => $rows) {
            $out[$name] = ['count' => $rows->count(), 'rows' => $rows->take(200)->values()->all()];
        }
        if ($this->option('json')) {
            $this->line(json_encode($out, JSON_PRETTY_PRINT));
        } else {
            $this->info('Read-only preflight. No data was changed.');
            foreach ($out as $name => $r) {
                $this->line(sprintf('%-52s %d', $name, $r['count']));
            }
        }

        return self::SUCCESS;
    }
}
