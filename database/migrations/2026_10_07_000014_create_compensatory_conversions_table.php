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
        Schema::create('compensatory_conversions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->decimal('hours_converted', 5, 2);
            $table->decimal('leave_days_added', 5, 2);
            $table->foreignId('leave_allocation_id')->constrained('leave_allocations')->onDelete('restrict');
            $table->decimal('daily_work_hours_snapshot', 4, 2);
            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensatory_conversions');
    }
};
