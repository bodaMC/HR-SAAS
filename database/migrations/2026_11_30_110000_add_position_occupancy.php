<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL-V3: configurable occupancy. Every existing position stays 'single' (one effective primary incumbent). 'multiple' allows several different
 * employees to hold primary assignments at the same time, optionally up to a capacity (NULL = unlimited). The per-employee placement exclusion
 * constraint is untouched; the one-incumbent rule for single positions is enforced under the tenant lock exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_positions', function (Blueprint $table) {
            $table->string('occupancy_type', 10)->default('single');
            $table->unsignedInteger('capacity')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE org_positions ADD CONSTRAINT org_pos_occupancy_chk CHECK (occupancy_type IN ('single','multiple') AND (capacity IS NULL OR (capacity >= 1 AND occupancy_type = 'multiple')))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_positions DROP CONSTRAINT IF EXISTS org_pos_occupancy_chk');
        }
        Schema::table('org_positions', fn (Blueprint $table) => $table->dropColumn(['occupancy_type', 'capacity']));
    }
};
