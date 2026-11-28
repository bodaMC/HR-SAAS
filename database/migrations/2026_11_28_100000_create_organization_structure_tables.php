<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 3.4: additive organizational structure. Nothing is created for existing employees and
| employees.manager_id (and the Phase 8 flags) are not touched.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });

        // Parents of the new tenant-composite foreign keys. (company_id, id) is trivially unique because id is the primary key; the index only
        // gives the foreign keys something to reference. Nothing about existing rows changes.
        Schema::table('employees', fn (Blueprint $table) => $table->unique(['company_id', 'id'], 'employees_company_id_id_unique'));
        Schema::table('departments', fn (Blueprint $table) => $table->unique(['company_id', 'id'], 'departments_company_id_id_unique'));

        Schema::create('org_positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title', 160);
            $table->string('title_en', 160)->nullable();
            $table->string('title_ar', 160)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('kind', 16)->default('position'); // position | body (non-person node, e.g. a board)
            $table->string('classification', 40)->nullable();
            $table->string('status', 16)->default('active'); // active | archived
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'department_id']);
            // A parent must belong to the SAME company (composite foreign key, enforced by the database).
            $table->foreign(['company_id', 'parent_id'])->references(['company_id', 'id'])->on('org_positions');
            // A department must belong to the SAME company. NO ACTION (not SET NULL): SET NULL would also null company_id. Departments are soft-deleted;
            // the application refuses to delete a department that still has active positions (see DepartmentController::destroy).
            $table->foreign(['company_id', 'department_id'])->references(['company_id', 'id'])->on('departments');
        });

        Schema::create('org_position_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('type', 16); // primary | acting | deputy
            $table->date('starts_on');
            $table->date('ends_on')->nullable(); // inclusive
            $table->string('status', 16)->default('active'); // active | ended | cancelled
            $table->boolean('is_primary_placement')->default(false);
            $table->string('reason', 500)->nullable();
            $table->string('end_cause', 32)->nullable(); // manual | employee_terminated | employee_deleted | position_archived | expired | correction | replaced
            $table->string('end_reason', 500)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'position_id', 'status']);
            $table->index(['company_id', 'employee_id', 'status']);
            $table->foreign(['company_id', 'position_id'])->references(['company_id', 'id'])->on('org_positions');
            $table->foreign(['company_id', 'employee_id'])->references(['company_id', 'id'])->on('employees');
        });

        Schema::create('org_reporting_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('from_parent_id')->nullable();
            $table->unsignedBigInteger('to_parent_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->index(['company_id', 'position_id']);
            $table->foreign(['company_id', 'position_id'])->references(['company_id', 'id'])->on('org_positions');
            $table->foreign(['company_id', 'from_parent_id'])->references(['company_id', 'id'])->on('org_positions');
            $table->foreign(['company_id', 'to_parent_id'])->references(['company_id', 'id'])->on('org_positions');
        });

        // Primary placement: an employee has at most one explicit placement AT ANY DATE. Placements may be consecutive (one ends, the next starts later),
        // so this is a date-window rule, not a plain unique index. OrgService enforces it under the tenant lock; PostgreSQL also gets an exclusion
        // constraint (below) as the database-level backstop.
        DB::statement('CREATE INDEX org_assign_placement_idx ON org_position_assignments (company_id, employee_id) WHERE is_primary_placement = true');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_position_assignments ADD CONSTRAINT org_assign_dates_ck CHECK (ends_on IS NULL OR ends_on >= starts_on)');
            DB::statement('ALTER TABLE org_positions ADD CONSTRAINT org_pos_not_self_parent_ck CHECK (parent_id IS NULL OR parent_id <> id)');
            DB::statement("ALTER TABLE org_positions ADD CONSTRAINT org_pos_status_ck CHECK (status IN ('active','archived'))");
            DB::statement("ALTER TABLE org_position_assignments ADD CONSTRAINT org_assign_type_ck CHECK (type IN ('primary','acting','deputy'))");
            // btree_gist is a trusted extension on PostgreSQL 13+. Where it is not available the service-level rule above still applies.
            $gist = DB::selectOne("select 1 as ok from pg_available_extensions where name = 'btree_gist'");
            if ($gist) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
                DB::statement("ALTER TABLE org_position_assignments ADD CONSTRAINT org_assign_one_placement_per_date EXCLUDE USING gist (company_id WITH =, employee_id WITH =, daterange(starts_on, coalesce(ends_on + 1, 'infinity'::date), '[)') WITH &&) WHERE (is_primary_placement AND status = 'active')");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('org_reporting_history');
        Schema::dropIfExists('org_position_assignments');
        Schema::dropIfExists('org_positions');
        Schema::table('employees', fn (Blueprint $table) => $table->dropUnique('employees_company_id_id_unique'));
        Schema::table('departments', fn (Blueprint $table) => $table->dropUnique('departments_company_id_id_unique'));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
