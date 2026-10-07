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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->foreignId('leave_type_id')->constrained('leave_types')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('calendar_days_count');
            $table->decimal('deducted_leave_days', 5, 2);
            $table->text('reason')->nullable();
            $table->boolean('is_late_submission')->default(false);
            $table->string('status', 50)->default('pending')->index();
            $table->boolean('is_implicit_approval')->default(false);
            $table->text('implicit_approval_reason')->nullable();
            $table->string('medical_certificate_path')->nullable();
            $table->string('medical_doctor_name')->nullable();
            $table->date('medical_examination_date')->nullable();
            $table->foreignId('final_actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('final_actioned_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
