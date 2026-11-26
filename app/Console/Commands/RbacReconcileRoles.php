<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Rbac\RoleReconciler;
use Illuminate\Console\Command;

class RbacReconcileRoles extends Command
{
    protected $signature = 'rbac:reconcile-roles {--company= : Company id or slug (default: every company)} {--dry-run : Report what would change, change nothing} {--json : Machine-readable report}';

    protected $description = 'Phase 3.3: idempotently reconcile the HR Administrator and System Super Admin roles with the revised permission matrix. Audited; supports dry-run.';

    public function handle(RoleReconciler $reconciler): int
    {
        $ref = (string) $this->option('company');
        $companies = Company::withoutGlobalScopes()->when($ref !== '', fn ($q) => ctype_digit($ref) ? $q->where('id', (int) $ref) : $q->where('slug', $ref))->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->error('No matching company. Nothing was changed.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $all = [];
        $blocked = false;
        foreach ($companies as $company) {
            try {
                $r = $reconciler->reconcile($company, $dry);
            } catch (\Throwable $e) {
                $this->error("Company #{$company->id}: failed and rolled back: ".$e->getMessage());

                return self::FAILURE;
            }
            $all[] = $r;
            $blocked = $blocked || $r['blocked'];
            if ($this->option('json')) {
                continue;
            }
            $this->line(($dry ? '[dry-run] ' : '')."Company #{$company->id} ({$company->slug})");
            foreach ($r['roles'] as $role) {
                $this->line(sprintf('  %-20s %s  +%d -%d permissions, +%d -%d delegations', $role['role'], $role['changed'] ? ($dry ? 'WOULD CHANGE' : 'UPDATED') : 'no change', count($role['add']), count($role['remove']), count($role['delegations_add']), count($role['delegations_remove'])));
            }
            foreach ($r['blocked'] as $b) {
                $this->warn("  BLOCKED {$b['role']}: ".implode(', ', $b['held_removals']).' not removed. '.$b['reason']);
            }
            foreach ($r['custom_roles_to_review'] as $c) {
                $this->warn("  REVIEW custom role #{$c['role_id']} '{$c['name']}': administrative=".implode(',', $c['administrative_permissions']).' stale='.implode(',', $c['no_longer_assignable']).' (not modified)');
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT));
        }

        return $blocked ? 2 : self::SUCCESS;
    }
}
