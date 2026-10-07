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
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->string('type', 50)->default('normal')->index();
            $table->date('date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('duration_hours', 4, 2);
            $table->boolean('is_late_submission')->default(false);
            $table->decimal('overtime_factor', 4, 2)->default(1.00);
            $table->decimal('salary_deduction_hours', 4, 2)->default(0.00);
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->boolean('is_implicit_approval')->default(false);
            $table->foreignId('final_actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('final_actioned_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'date']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
    }
};
