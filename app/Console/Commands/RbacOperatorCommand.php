<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Rbac\RbacDenied;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Console\Command;

/** Shared, fail-closed plumbing for the trusted operator RBAC commands (CLI only, no HTTP route). */
abstract class RbacOperatorCommand extends Command
{
    protected function company(): ?Company
    {
        $ref = (string) $this->option('company');
        if ($ref === '') {
            return null;
        }

        return Company::withoutGlobalScopes()->where(fn ($q) => ctype_digit($ref) ? $q->where('id', (int) $ref) : $q->where('slug', $ref))->first();
    }

    protected function accountIn(Company $company, string $email): ?User
    {
        $email = mb_strtolower(trim($email));

        return $email === '' ? null : User::withoutGlobalScopes()->where('company_id', $company->id)->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /** Run the service call in the tenant context and map refusals to a clean failure. */
    protected function guarded(Company $company, Closure $call): int|string
    {
        $context = app(TenantContext::class);
        $context->set($company);
        try {
            return $call();
        } catch (RbacDenied $e) {
            $this->error($e->getMessage().' Nothing was changed.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Failed and rolled back: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $context->clear();
        }
    }
}
