<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\NationalId;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function nid_actor(?Company $company = null, UserRole $role = UserRole::Owner): array
{
    $company ??= Company::factory()->create();
    app(TenantContext::class)->set($company);
    $user = User::factory()->hr()->create(['role' => $role]);
    $token = $user->createToken('t')->plainTextToken;
    app(TenantContext::class)->clear();

    return [$company, $token];
}

function nid_payload(array $over = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'employee_number' => "N-$n",
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => "nid$n@example.test",
    ], $over);
}

function nid_post($t, string $token, array $body)
{
    app('auth')->forgetGuards();

    return $t->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/employees', $body);
}

const NID_A = '29001011234567';
const NID_B = '29505051234567';

test('normalizer maps Arabic and Persian digits, strips spaces and hyphens, empties to null', function () {
    expect(NationalId::normalize('٢٩٠٠١٠١١٢٣٤٥٦٧'))->toBe(NID_A)
        ->and(NationalId::normalize('۲۹۰۰۱۰۱۱۲۳۴۵۶۷'))->toBe(NID_A)
        ->and(NationalId::normalize(' 2900 1011-234567 '))->toBe(NID_A)
        ->and(NationalId::normalize("\u{200F}29001011234567\u{00A0}"))->toBe(NID_A)
        ->and(NationalId::normalize(''))->toBeNull()
        ->and(NationalId::normalize('   '))->toBeNull()
        ->and(NationalId::normalize(null))->toBeNull()
        ->and(NationalId::mask(NID_A))->toBe('**********4567')
        ->and(NationalId::mask(null))->toBe('(empty)');
});

test('create stores the canonical value and rejects a duplicate in the same tenant with a field-level 422', function () {
    [, $token] = nid_actor();
    nid_post($this, $token, nid_payload(['national_id' => NID_A]))->assertCreated()->assertJsonPath('data.national_id', NID_A);

    nid_post($this, $token, nid_payload(['national_id' => NID_A]))
        ->assertStatus(422)
        ->assertJsonPath('errors.national_id.0', 'This national ID is already registered to another employee.');
});

test('Arabic-digit and spaced variants of an existing ID are duplicates', function () {
    [, $token] = nid_actor();
    nid_post($this, $token, nid_payload(['national_id' => NID_A]))->assertCreated();
    nid_post($this, $token, nid_payload(['national_id' => '٢٩٠٠١٠١١٢٣٤٥٦٧']))->assertStatus(422)->assertJsonValidationErrors('national_id');
    nid_post($this, $token, nid_payload(['national_id' => '2900 1011 234567']))->assertStatus(422)->assertJsonValidationErrors('national_id');
});

test('duplicate message is returned in Arabic when Accept-Language is ar', function () {
    [, $token] = nid_actor();
    nid_post($this, $token, nid_payload(['national_id' => NID_A]))->assertCreated();
    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept-Language' => 'ar'])
        ->postJson('/api/v1/employees', nid_payload(['national_id' => NID_A]))
        ->assertStatus(422)
        ->assertJsonPath('errors.national_id.0', 'الرقم القومي مسجل بالفعل لموظف آخر.');
});

test('Egyptian format is exactly 14 digits and stays optional', function (string $bad) {
    [, $token] = nid_actor();
    nid_post($this, $token, nid_payload(['national_id' => $bad]))->assertStatus(422)->assertJsonValidationErrors('national_id');
})->with(['too short' => '1234567890123', 'too long' => '123456789012345', 'letters' => '2900101123456A', 'prefix' => 'EGY-1234']);

test('national ID is optional and many employees may have none (NULL and empty string)', function () {
    [$company, $token] = nid_actor();
    nid_post($this, $token, nid_payload())->assertCreated()->assertJsonPath('data.national_id', null);
    nid_post($this, $token, nid_payload(['national_id' => null]))->assertCreated();
    nid_post($this, $token, nid_payload(['national_id' => '']))->assertCreated()->assertJsonPath('data.national_id', null);
    nid_post($this, $token, nid_payload(['national_id' => '   ']))->assertCreated()->assertJsonPath('data.national_id', null);
    expect(DB::table('employees')->where('company_id', $company->id)->whereNull('national_id')->count())->toBe(4);
});

test('the same ID may exist in different tenants', function () {
    [, $tokenA] = nid_actor();
    [, $tokenB] = nid_actor();
    nid_post($this, $tokenA, nid_payload(['national_id' => NID_A]))->assertCreated();
    nid_post($this, $tokenB, nid_payload(['national_id' => NID_A]))->assertCreated();
});

test('update: an employee may keep their own ID but may not take another employee ID', function () {
    [, $token] = nid_actor();
    $a = nid_post($this, $token, nid_payload(['national_id' => NID_A]))->json('data.id');
    $b = nid_post($this, $token, nid_payload(['national_id' => NID_B]))->json('data.id');
    $h = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($h)->putJson("/api/v1/employees/$a", ['national_id' => NID_A, 'first_name' => 'Renamed'])->assertOk();
    $this->withHeaders($h)->putJson("/api/v1/employees/$a", ['national_id' => '٢٩٠٠١٠١١٢٣٤٥٦٧'])->assertOk();
    $this->withHeaders($h)->putJson("/api/v1/employees/$a", ['national_id' => NID_B])
        ->assertStatus(422)->assertJsonPath('errors.national_id.0', 'This national ID is already registered to another employee.');
    $this->withHeaders($h)->putJson("/api/v1/employees/$b", ['national_id' => null])->assertOk()->assertJsonPath('data.national_id', null);
    $this->withHeaders($h)->putJson("/api/v1/employees/$a", ['national_id' => NID_B])->assertOk();
});

test('soft-deleted and terminated employees still reserve their ID', function () {
    [$company, $token] = nid_actor();
    $a = nid_post($this, $token, nid_payload(['national_id' => NID_A]))->json('data.id');
    $b = nid_post($this, $token, nid_payload(['national_id' => NID_B, 'employment_status' => 'terminated']))->json('data.id');
    $this->withHeader('Authorization', 'Bearer '.$token)->deleteJson("/api/v1/employees/$a")->assertSuccessful();
    expect(DB::table('employees')->where('id', $a)->whereNotNull('deleted_at')->exists())->toBeTrue();

    nid_post($this, $token, nid_payload(['national_id' => NID_A]))->assertStatus(422)->assertJsonValidationErrors('national_id');
    nid_post($this, $token, nid_payload(['national_id' => NID_B]))->assertStatus(422)->assertJsonValidationErrors('national_id');
});

test('database unique index rejects a duplicate that bypasses validation', function () {
    [$company] = nid_actor();
    app(TenantContext::class)->set($company);
    Employee::factory()->create(['national_id' => NID_A]);
    expect(fn () => DB::transaction(fn () => Employee::factory()->create(['national_id' => NID_A])))->toThrow(UniqueConstraintViolationException::class);
    // different tenant and NULLs are unaffected
    Employee::factory()->count(3)->create(['national_id' => null]);
    app(TenantContext::class)->clear();
    $other = Company::factory()->create();
    app(TenantContext::class)->set($other);
    Employee::factory()->create(['national_id' => NID_A]);
    app(TenantContext::class)->clear();
});

test('a concurrent duplicate that wins the race at the database becomes a safe field-level 422', function () {
    [$company, $token] = nid_actor();
    // Simulate the race deterministically: validation has already passed when a
    // competing request commits the same ID right before our INSERT.
    $fired = false;
    Employee::creating(function (Employee $e) use (&$fired, $company) {
        if ($fired || $e->national_id !== NID_A) {
            return;
        }
        $fired = true;
        DB::table('employees')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
            'employee_number' => 'RACE-1', 'first_name' => 'Race', 'last_name' => 'Winner',
            'email' => 'race@example.test', 'national_id' => NID_A, 'employment_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $res = nid_post($this, $token, nid_payload(['national_id' => NID_A]));
    $res->assertStatus(422)
        ->assertJsonPath('errors.national_id.0', 'This national ID is already registered to another employee.');
    expect($res->getContent())->not->toContain('SQLSTATE')->not->toContain('employees_company_national_id_unique');
    // Note: inside RefreshDatabase the competing row shares our savepoint, so its
    // count is not asserted here; real parallel commits are proven in the
    // PostgreSQL concurrency script (see report).
    expect($fired)->toBeTrue();
});

test('an employee keeping a legacy non-14-digit ID can still save, but cannot switch to another invalid one', function () {
    [$company, $token] = nid_actor();
    app(TenantContext::class)->set($company);
    $legacy = Employee::factory()->create(['national_id' => 'LEGACY1']);
    app(TenantContext::class)->clear();
    $h = ['Authorization' => 'Bearer '.$token];

    $this->withHeaders($h)->putJson("/api/v1/employees/{$legacy->id}", ['national_id' => 'LEGACY1', 'first_name' => 'Kept'])->assertOk();
    $this->withHeaders($h)->putJson("/api/v1/employees/{$legacy->id}", ['national_id' => 'LEGACY2'])->assertStatus(422)->assertJsonValidationErrors('national_id');
});
