<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL-V3: administrative corrections. A NEW migration on purpose: FINAL-V2 databases already ran the structure migration.
 * Additive and nullable; no existing row changes. Voiding keeps the row (status cancelled, cause "correction") and records who/when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_position_assignments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correction_id')->nullable(); // links the corrected/ended row and its replacement
            $table->unsignedBigInteger('replaces_assignment_id')->nullable();
            $table->unique(['company_id', 'id'], 'org_assign_company_id_id_unique');
            $table->index(['company_id', 'correction_id'], 'org_assign_correction_idx');
            $table->foreign(['company_id', 'replaces_assignment_id'], 'org_assign_replaces_fk')->references(['company_id', 'id'])->on('org_position_assignments');
        });
    }

    public function down(): void
    {
        Schema::table('org_position_assignments', function (Blueprint $table) {
            $table->dropForeign('org_assign_replaces_fk');
            $table->dropIndex('org_assign_correction_idx');
            $table->dropUnique('org_assign_company_id_id_unique');
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['voided_at', 'voided_by', 'correction_id', 'replaces_assignment_id']);
        });
    }
};
