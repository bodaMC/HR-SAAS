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
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->morphs('approvable');
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedSmallInteger('current_step_order')->default(1);
            $table->unsignedSmallInteger('total_steps')->default(1);
            $table->timestamps();
        });

        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->onDelete('cascade');
            $table->unsignedSmallInteger('step_order');
            $table->string('role_type', 50); // team_leader, project_engineer, hod, ceo
            $table->foreignId('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 50)->default('pending')->index(); // pending, approved, rejected, skipped
            $table->string('skipped_reason')->nullable();
            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
