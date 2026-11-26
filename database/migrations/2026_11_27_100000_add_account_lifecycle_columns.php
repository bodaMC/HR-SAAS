<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.3 (additive, non-destructive). Nothing here changes an existing account,
 * link, password, role or permission:
 * - users.must_change_password defaults to false, so existing accounts are NOT forced
 *   to change their password.
 * - employees.employment_type stays NULL ("not specified") for every existing row.
 * - employees.is_trainee defaults to false.
 * - audit_logs.visibility is backfilled from an explicit allowlist of operational
 *   actions; anything else (RBAC, protected-account, platform events, unknown actions)
 *   stays 'restricted' (fail closed).
 */
return new class extends Migration
{
    /** Frozen copy of the operational actions that exist before Phase 3.3. */
    private const LEGACY_OPERATIONAL = [
        'create_holiday', 'delete_holiday', 'update_work_schedule', 'create_leave_type', 'update_leave_type',
        'create_project', 'update_project', 'delete_project', 'assign_project_employees',
        'create_permission_request', 'approve_permission_step', 'reject_permission_request', 'cancel_permission_request',
        'create_leave_request', 'approve_leave_step', 'reject_leave_request', 'cancel_leave_request',
        'accrue_compensatory_time', 'convert_compensatory_to_leave', 'calculate_statutory_allocation', 'store_leave_allocation',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type', 20)->nullable()->index();
            $table->boolean('is_trainee')->default(false)->index();
            $table->string('avatar_path', 255)->nullable();
            $table->timestamp('avatar_updated_at')->nullable();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('reason')->nullable();
            $table->string('visibility', 20)->default('restricted')->index();
        });

        DB::table('audit_logs')->whereIn('action', self::LEGACY_OPERATIONAL)->update(['visibility' => 'operational']);
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropColumn(['reason', 'visibility']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employment_type']);
            $table->dropIndex(['is_trainee']);
            $table->dropColumn(['employment_type', 'is_trainee', 'avatar_path', 'avatar_updated_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
