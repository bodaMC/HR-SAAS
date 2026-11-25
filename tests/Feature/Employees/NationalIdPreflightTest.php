<?php

use App\Models\Company;
use App\Support\NationalIdPreflight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pf_row(int $company, string $num, ?string $nid, ?string $deleted = null, string $status = 'active'): int
{
    return DB::table('employees')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company, 'employee_number' => $num,
        'first_name' => 'F', 'last_name' => 'L', 'email' => "$num@example.test", 'national_id' => $nid,
        'employment_status' => $status, 'deleted_at' => $deleted, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pf_drop_index(): void
{
    DB::statement('DROP INDEX IF EXISTS employees_company_national_id_unique');
}

test('preflight is clean on healthy data and exits 0', function () {
    $c = Company::factory()->create();
    pf_row($c->id, 'A', '29001011234567');
    pf_row($c->id, 'B', null);
    $this->artisan('employees:national-id-preflight')->assertExitCode(0);
});

test('preflight reports duplicates incl. soft-deleted, masks IDs, and changes nothing', function () {
    $c = Company::factory()->create();
    $c2 = Company::factory()->create();
    pf_drop_index();
    $a = pf_row($c->id, 'A', '29001011234567');
    $b = pf_row($c->id, 'B', '29001011234567', now()->toDateTimeString());
    pf_row($c->id, 'C', '29001011234567', null, 'terminated');
    pf_row($c2->id, 'D', '29001011234567'); // other tenant: not a duplicate
    $before = DB::table('employees')->orderBy('id')->get()->toJson();

    $res = NationalIdPreflight::run();
    expect($res['duplicate_groups'])->toHaveCount(1)
        ->and($res['duplicate_groups'][0]['count'])->toBe(3)
        ->and(collect($res['duplicate_groups'][0]['employees'])->pluck('state')->all())->toContain('soft_deleted', 'terminated')
        ->and(json_encode($res))->not->toContain('29001011234567');

    $this->artisan('employees:national-id-preflight')
        ->expectsOutputToContain('NOT SAFE')
        ->assertExitCode(1);
    expect(DB::table('employees')->orderBy('id')->get()->toJson())->toBe($before);
});

test('preflight flags Arabic-digit duplicates and non-canonical values', function () {
    $c = Company::factory()->create();
    pf_drop_index();
    pf_row($c->id, 'A', '29001011234567');
    pf_row($c->id, 'B', '٢٩٠٠١٠١١٢٣٤٥٦٧');
    pf_row($c->id, 'C', '');
    $res = NationalIdPreflight::run();
    expect($res['duplicate_groups'])->toHaveCount(1)->and($res['non_canonical'])->toBe(2);
});

test('migration refuses to create the index when duplicates exist and leaves data and schema untouched', function () {
    $c = Company::factory()->create();
    pf_drop_index();
    pf_row($c->id, 'A', '29001011234567');
    pf_row($c->id, 'B', '29001011234567');
    $migration = require base_path('database/migrations/2026_11_25_100000_add_unique_national_id_per_company_to_employees.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'preflight failed');
    expect(DB::table('employees')->count())->toBe(2);
    // index still absent: a second duplicate insert still works at DB level
    pf_row($c->id, 'C', '29001011234567');
    expect(DB::table('employees')->count())->toBe(3);
});

test('migration up creates the index on clean data and down removes it (rollback)', function () {
    $c = Company::factory()->create();
    pf_drop_index();
    pf_row($c->id, 'A', '29001011234567');
    $migration = require base_path('database/migrations/2026_11_25_100000_add_unique_national_id_per_company_to_employees.php');
    $migration->up();
    expect(fn () => DB::transaction(fn () => pf_row($c->id, 'B', '29001011234567')))->toThrow(UniqueConstraintViolationException::class);
    $migration->down();
    pf_row($c->id, 'B', '29001011234567');
    expect(DB::table('employees')->count())->toBe(2);
});
