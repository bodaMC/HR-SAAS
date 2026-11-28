<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL-V3: department-aware job titles and department-aware direct manager eligibility.
 * Additive. Every existing job title stays available to ALL departments ('all') until an administrator configures it,
 * no employee row is touched, and nothing is inferred from names. Manager scopes start empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_titles', function (Blueprint $table) {
            $table->string('department_availability', 10)->default('all');
            $table->unique(['company_id', 'id'], 'job_titles_company_id_id_unique');
        });
        $pg = DB::getDriverName() === 'pgsql'; // CHECK constraints are added after the fact on PostgreSQL only (SQLite cannot ALTER ADD CONSTRAINT)
        $pg && DB::statement("ALTER TABLE job_titles ADD CONSTRAINT job_titles_availability_chk CHECK (department_availability IN ('all','selected'))");

        Schema::create('job_title_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('job_title_id');
            $table->unsignedBigInteger('department_id');
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'job_title_id'], 'jtd_title_fk')->references(['company_id', 'id'])->on('job_titles');
            $table->foreign(['company_id', 'department_id'], 'jtd_dept_fk')->references(['company_id', 'id'])->on('departments');
            $table->unique(['job_title_id', 'department_id'], 'jtd_unique');
        });

        // One row per explicit managerial scope of an employee: one department, or every department (department_id NULL, scope 'all').
        Schema::create('manager_department_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('scope', 12);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign(['company_id', 'employee_id'], 'mds_emp_fk')->references(['company_id', 'id'])->on('employees');
            $table->foreign(['company_id', 'department_id'], 'mds_dept_fk')->references(['company_id', 'id'])->on('departments');
            $table->unique(['employee_id', 'department_id'], 'mds_unique_dept');
        });
        $pg && DB::statement("ALTER TABLE manager_department_scopes ADD CONSTRAINT mds_scope_chk CHECK ((scope = 'all' AND department_id IS NULL) OR (scope = 'department' AND department_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX mds_unique_all ON manager_department_scopes (employee_id) WHERE scope = 'all'");
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_department_scopes');
        Schema::dropIfExists('job_title_departments');
        DB::getDriverName() === 'pgsql' && DB::statement('ALTER TABLE job_titles DROP CONSTRAINT IF EXISTS job_titles_availability_chk');
        Schema::table('job_titles', function (Blueprint $table) {
            $table->dropUnique('job_titles_company_id_id_unique');
            $table->dropColumn('department_availability');
        });
    }
};
