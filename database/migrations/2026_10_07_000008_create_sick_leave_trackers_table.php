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
        Schema::create('sick_leave_trackers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->unsignedSmallInteger('year')->index();
            $table->decimal('policy_threshold_days', 4, 2)->default(7.00);
            $table->decimal('used_sick_days', 5, 2)->default(0.00);
            $table->decimal('excess_sick_days', 5, 2)->default(0.00);
            $table->decimal('total_penalty_deduction_days', 5, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sick_leave_trackers');
    }
};
