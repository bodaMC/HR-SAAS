<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('hod_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('ceo_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('team_leader_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_disabled')->default(false);
            $table->decimal('insurance_years', 4, 2)->default(0.00);
            $table->boolean('is_hod')->default(false);
            $table->boolean('is_team_leader')->default(false);
            $table->boolean('is_project_engineer')->default(false);
            $table->boolean('is_ceo')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['team_leader_id']);
            $table->dropColumn([
                'team_leader_id',
                'is_disabled',
                'insurance_years',
                'is_hod',
                'is_team_leader',
                'is_project_engineer',
                'is_ceo',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['ceo_employee_id']);
            $table->dropColumn('ceo_employee_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['hod_employee_id']);
            $table->dropColumn('hod_employee_id');
        });
    }
};
