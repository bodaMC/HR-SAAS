<?php

use App\Support\NationalIdPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant uniqueness of employees.national_id (BUG-003).
 *
 * - Partial unique index: NULLs are free (many employees without an ID), and
 *   soft-deleted rows are deliberately INCLUDED so a deleted/terminated
 *   employee's ID cannot be reused silently.
 * - Safe by construction: the migration first runs the read-only preflight and
 *   aborts BEFORE changing anything if duplicates or non-canonical values exist.
 *   It never deletes, merges or rewrites data.
 * - Rollback only drops the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = NationalIdPreflight::run();
        $dups = count($r['duplicate_groups']);
        if ($dups > 0 || $r['non_canonical'] > 0) {
            throw new RuntimeException(
                "National ID preflight failed: {$dups} duplicate group(s), {$r['non_canonical']} non-canonical value(s). "
                ."No schema change was made.\n".NationalIdPreflight::remediation()
            );
        }

        DB::statement('CREATE UNIQUE INDEX employees_company_national_id_unique ON employees (company_id, national_id) WHERE national_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employees_company_national_id_unique');
    }
};
