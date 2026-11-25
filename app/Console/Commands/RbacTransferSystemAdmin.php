<?php

namespace App\Console\Commands;

use App\Rbac\RbacService;

class RbacTransferSystemAdmin extends RbacOperatorCommand
{
    protected $signature = 'rbac:transfer-system-admin {--company= : Company id or slug} {--from= : Email of the current System Super Admin} {--to= : Email of the active replacement account} {--reason= : Mandatory reason (15-500 characters)} {--dry-run : Validate and report, change nothing}';

    protected $description = 'Trusted, atomic ownership transfer of the System Super Admin role inside ONE company.';

    public function handle(RbacService $rbac): int
    {
        $company = $this->company();
        $from = $company ? $this->accountIn($company, (string) $this->option('from')) : null;
        $to = $company ? $this->accountIn($company, (string) $this->option('to')) : null;
        if (! $company || ! $from || ! $to) {
            $this->error('A valid --company and existing --from and --to accounts of that company are required. Nothing was changed.');

            return self::FAILURE;
        }
        $this->line("Company #{$company->id} ({$company->slug}); from account #{$from->id} to account #{$to->id}");
        $out = $this->guarded($company, fn () => $rbac->transferSystemAdmin($company, $from, $to, (string) $this->option('reason'), (bool) $this->option('dry-run')));
        if (is_int($out)) {
            return $out;
        }
        $this->info(match ($out) {
            'dry_run' => 'Dry run: ownership would be transferred. Nothing was changed.',
            'already' => 'No change: the replacement already is the System Super Admin (idempotent).',
            default => 'System Super Admin transferred atomically (two audit entries written).',
        });

        return self::SUCCESS;
    }
}
