<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Rbac\RbacDenied;
use App\Rbac\RbacService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RbacBootstrapHr extends Command
{
    protected $signature = 'rbac:bootstrap-hr {--company= : Company id or slug} {--email= : Email of an existing active account of that company} {--reason= : Optional reason recorded in the audit entry} {--dry-run : Show what would happen, change nothing}';

    protected $description = 'Trusted bootstrap: assign the HR Administrator role to ONE existing account of ONE company (explicit, audited).';

    public function handle(RbacService $rbac): int
    {
        $ref = (string) $this->option('company');
        $email = mb_strtolower(trim((string) $this->option('email')));
        if ($ref === '' || $email === '') {
            $this->error('Both --company and --email are required. Nothing was changed.');

            return self::FAILURE;
        }

        $company = Company::withoutGlobalScopes()->where(fn ($q) => ctype_digit($ref) ? $q->where('id', (int) $ref) : $q->where('slug', $ref))->first();
        if (! $company) {
            $this->error('Company not found. Nothing was changed.');

            return self::FAILURE;
        }
        $user = User::withoutGlobalScopes()->where('company_id', $company->id)->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            $this->error('No account with that email in this company. Nothing was changed.');

            return self::FAILURE;
        }
        if ($user->isSuperAdmin() || ! $user->is_active) {
            $this->error('Refused: the account must be active and must not be the platform account. Nothing was changed.');

            return self::FAILURE;
        }

        $this->line("Company #{$company->id} ({$company->slug}); account #{$user->id}");
        if ($this->option('dry-run')) {
            $this->info('Dry run: the HR Administrator role would be assigned. Nothing was changed.');

            return self::SUCCESS;
        }

        $context = app(TenantContext::class);
        $context->set($company);
        try {
            $rbac->bootstrapHr($company, $user, null, ((string) $this->option('reason')) ?: null);
        } catch (RbacDenied $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $context->clear();
        }
        $this->info('HR Administrator role assigned (audit entry rbac.bootstrap_hr written).');

        return self::SUCCESS;
    }
}
