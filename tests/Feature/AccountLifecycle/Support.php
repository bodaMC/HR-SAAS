<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Rbac\PermissionResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const LC_PW = 'Str0ng!Passw0rd#1';

/** Create a user in the company. $state: employee|hr|sysadmin|owner|admin|super. Returns [user, token]. */
function lc_user(Company $company, string $state = 'employee', array $attrs = []): array
{
    app(TenantContext::class)->set($company);
    $f = User::factory();
    $f = match ($state) {
        'hr' => $f->hr(),
        'sysadmin' => $f->systemAdmin(),
        'owner' => $f->owner(),
        'admin' => $f->admin(),
        default => $f,
    };
    $user = $f->create($attrs + ($state === 'hr' ? ['role' => UserRole::Employee] : []));
    if ($state === 'super') {
        $user->is_super_admin = true;
        $user->save();
    }
    $token = $user->createToken('t')->plainTextToken;
    app(TenantContext::class)->clear();
    PermissionResolver::flush();

    return [$user, $token];
}

function lc_emp(Company $company, array $attrs = []): Employee
{
    app(TenantContext::class)->set($company);
    $e = Employee::factory()->create($attrs);
    app(TenantContext::class)->clear();

    return $e;
}

/** Custom (non system) role with the given permissions assigned to the user. */
function lc_role(Company $company, array $perms, ?User $assignTo = null, string $name = 'Custom'): int
{
    $id = DB::table('roles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'key' => 'custom_'.Str::lower(Str::random(8)),
        'name' => $name.' '.Str::random(4), 'is_system' => false, 'is_protected' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($perms as $p) {
        DB::table('role_permissions')->insert(['role_id' => $id, 'permission' => $p, 'created_at' => now()]);
    }
    if ($assignTo) {
        DB::table('user_roles')->insert(['company_id' => $company->id, 'user_id' => $assignTo->id, 'role_id' => $id, 'created_at' => now()]);
    }
    PermissionResolver::flush();

    return $id;
}

function lc_call($test, ?string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();
    PermissionResolver::flush();
    $t = $token ? $test->withToken($token) : $test;

    return $t->json($method, $uri, $data);
}

/** A tenant with SSA, two HR accounts, one ordinary employee account and employee records for each. */
function lc_world(string $d = 'a.test'): array
{
    $c = Company::factory()->create();
    [$sa, $saT] = lc_user($c, 'sysadmin', ['email' => "sa@$d"]);
    [$hr, $hrT] = lc_user($c, 'hr', ['email' => "hr1@$d"]);
    [$hr2, $hr2T] = lc_user($c, 'hr', ['email' => "hr2@$d"]);
    [$emp, $empT] = lc_user($c, 'employee', ['email' => "emp@$d"]);
    $eSa = lc_emp($c, ['user_id' => $sa->id, 'email' => "w.sa@$d"]);
    $eHr = lc_emp($c, ['user_id' => $hr->id, 'email' => "w.hr1@$d"]);
    $eHr2 = lc_emp($c, ['user_id' => $hr2->id, 'email' => "w.hr2@$d"]);
    $eEmp = lc_emp($c, ['user_id' => $emp->id, 'email' => "w.emp@$d"]);
    $eNone = lc_emp($c, ['email' => "w.none@$d"]);

    return compact('c', 'sa', 'saT', 'hr', 'hrT', 'hr2', 'hr2T', 'emp', 'empT', 'eSa', 'eHr', 'eHr2', 'eEmp', 'eNone');
}

function lc_body(Employee $e, array $extra = []): array
{
    return ['confirm_employee_uuid' => $e->uuid, 'reason' => 'Synthetic lifecycle test reason'] + $extra;
}
