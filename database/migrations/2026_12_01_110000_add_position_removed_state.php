<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UAT-02 (FINAL-V3 R4): a position that has history is removed with a tombstone instead of being erased. Additive and non-destructive:
 * three nullable columns and a wider status CHECK ('removed' joins 'active' and 'archived'). No existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_positions', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removed_reason', 500)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_positions DROP CONSTRAINT IF EXISTS org_pos_status_ck');
            DB::statement("ALTER TABLE org_positions ADD CONSTRAINT org_pos_status_ck CHECK (status IN ('active','archived','removed'))");
        }
    }

    public function down(): void
    {
        // Removed positions cannot exist under the old constraint: they come back as archived (hidden from the chart, recoverable by restore).
        DB::table('org_positions')->where('status', 'removed')->update(['status' => 'archived']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE org_positions DROP CONSTRAINT IF EXISTS org_pos_status_ck');
            DB::statement("ALTER TABLE org_positions ADD CONSTRAINT org_pos_status_ck CHECK (status IN ('active','archived'))");
        }
        Schema::table('org_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['removed_at', 'removed_reason']);
        });
    }
};
