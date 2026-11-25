<?php

namespace App\Console\Commands;

use App\Rbac\RbacService;

class RbacBootstrapSystemAdmin extends RbacOperatorCommand
{
    protected $signature = 'rbac:bootstrap-system-admin {--company= : Company id or slug} {--email= : Email of an existing active account of that company} {--reason= : Mandatory reason (15-500 characters)} {--dry-run : Validate and report, change nothing}';

    protected $description = 'Trusted provisioning of the single System Super Admin of ONE company (explicit account, mandatory reason, audited, idempotent).';

    public function handle(RbacService $rbac): int
    {
        $company = $this->company();
        if (! $company || (string) $this->option('email') === '') {
            $this->error('A valid --company and an --email are required. Nothing was changed.');

            return self::FAILURE;
        }
        $user = $this->accountIn($company, (string) $this->option('email'));
        if (! $user) {
            $this->error('No account with that email in this company. Nothing was changed.');

            return self::FAILURE;
        }
        $this->line("Company #{$company->id} ({$company->slug}); account #{$user->id}");
        $out = $this->guarded($company, fn () => $rbac->bootstrapSystemAdmin($company, $user, (string) $this->option('reason'), (bool) $this->option('dry-run')));
        if (is_int($out)) {
            return $out;
        }
        $this->info(match ($out) {
            'dry_run' => 'Dry run: the System Super Admin role would be assigned. Nothing was changed.',
            'already' => 'No change: this account already is the System Super Admin (idempotent).',
            default => 'System Super Admin role assigned (audit entry rbac.bootstrap_system_admin written).',
        });

        return self::SUCCESS;
    }
}
