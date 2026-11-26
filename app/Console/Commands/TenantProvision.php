<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Rbac\RbacService;
use App\Support\PasswordPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvision extends Command
{
    protected $signature = 'tenant:provision {--name= : Company name} {--slug= : Company slug (default: from the name)} {--admin-name= : Name of the first administrator} {--admin-email= : Login email of the first administrator} {--reason= : Mandatory reason (15-500 characters)} {--dry-run : Validate only}';

    protected $description = 'Trusted operator procedure (the only way to create a company): provisions the company and its single System Super Admin with a generated one-time temporary password (change forced at first sign-in).';

    public function handle(RbacService $rbac): int
    {
        $name = trim((string) $this->option('name'));
        $email = mb_strtolower(trim((string) $this->option('admin-email')));
        $adminName = trim((string) $this->option('admin-name'));
        if ($name === '' || $adminName === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--name, --admin-name and a valid --admin-email are required. Nothing was changed.');

            return self::FAILURE;
        }
        $slug = Str::slug((string) ($this->option('slug') ?: $name)) ?: 'company-'.Str::lower(Str::random(6));
        if (Company::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $this->error("The slug '{$slug}' is taken. Nothing was changed.");

            return self::FAILURE;
        }
        if (User::withoutGlobalScopes()->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('That email already exists (emails are globally unique). Nothing was changed.');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info("Dry run: would provision company '{$name}' ({$slug}) with System Super Admin {$email}. Nothing was changed.");

            return self::SUCCESS;
        }
        $password = PasswordPolicy::generate();
        $context = app(TenantContext::class);
        try {
            $company = DB::transaction(function () use ($name, $slug, $adminName, $email, $password, $rbac) {
                $company = Company::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
                app(TenantContext::class)->set($company);
                $user = new User;
                $user->forceFill(['name' => $adminName, 'email' => $email, 'password' => $password, 'role' => UserRole::Employee, 'is_active' => true, 'is_super_admin' => false, 'must_change_password' => true]);
                $user->save();
                $rbac->bootstrapSystemAdmin($company, $user, (string) $this->option('reason'));

                return $company;
            });
        } catch (\Throwable $e) {
            $this->error('Failed and rolled back: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $context->clear();
        }
        $this->info("Company #{$company->id} ({$company->slug}) provisioned. System Super Admin: {$email}");
        $this->warn("One-time temporary password (shown once, not stored anywhere): {$password}");

        return self::SUCCESS;
    }
}
