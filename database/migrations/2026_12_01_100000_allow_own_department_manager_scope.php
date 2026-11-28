<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UAT-01 (FINAL-V3 R4): adds the explicit "own department only" manager configuration to manager_department_scopes.
 * Additive: existing rows ('all' and 'department') are untouched. The scope value 'own' has no department (it follows the employee's current department).
 * It only widens the PostgreSQL CHECK constraint and adds a one-row-per-employee index for it. No data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE manager_department_scopes DROP CONSTRAINT IF EXISTS mds_scope_chk');
            DB::statement("ALTER TABLE manager_department_scopes ADD CONSTRAINT mds_scope_chk CHECK ((scope IN ('all','own') AND department_id IS NULL) OR (scope = 'department' AND department_id IS NOT NULL))");
        }
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS mds_unique_own ON manager_department_scopes (employee_id) WHERE scope = 'own'");
    }

    public function down(): void
    {
        // Rows with the new value cannot exist under the old constraint; they are the only data this rollback removes (the explicit "own department" settings).
        DB::table('manager_department_scopes')->where('scope', 'own')->delete();
        DB::statement('DROP INDEX IF EXISTS mds_unique_own');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE manager_department_scopes DROP CONSTRAINT IF EXISTS mds_scope_chk');
            DB::statement("ALTER TABLE manager_department_scopes ADD CONSTRAINT mds_scope_chk CHECK ((scope = 'all' AND department_id IS NULL) OR (scope = 'department' AND department_id IS NOT NULL))");
        }
    }
};
