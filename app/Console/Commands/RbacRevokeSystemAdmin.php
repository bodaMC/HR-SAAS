<?php

namespace App\Console\Commands;

use App\Rbac\RbacService;

class RbacRevokeSystemAdmin extends RbacOperatorCommand
{
    protected $signature = 'rbac:revoke-system-admin {--company= : Company id or slug} {--email= : Email of the account} {--reason= : Mandatory reason (15-500 characters)} {--dry-run : Validate and report, change nothing}';

    protected $description = 'Trusted revocation of the System Super Admin role from ONE account (mandatory reason, audited, lockout-protected, idempotent).';

    public function handle(RbacService $rbac): int
    {
        $company = $this->company();
        $user = $company ? $this->accountIn($company, (string) $this->option('email')) : null;
        if (! $company || ! $user) {
            $this->error('A valid --company and an existing --email of that company are required. Nothing was changed.');

            return self::FAILURE;
        }
        $this->line("Company #{$company->id} ({$company->slug}); account #{$user->id}");
        $out = $this->guarded($company, fn () => $rbac->revokeSystemAdmin($company, $user, (string) $this->option('reason'), (bool) $this->option('dry-run')));
        if (is_int($out)) {
            return $out;
        }
        $this->info(match ($out) {
            'dry_run' => 'Dry run: the role would be removed and a permission administrator would remain. Nothing was changed.',
            'none' => 'No change: the account does not hold this role (idempotent).',
            default => 'Role removed (audit entry rbac.revoke_system_admin written).',
        });

        return self::SUCCESS;
    }
}
