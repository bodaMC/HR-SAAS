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
        Schema::create('company_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->string('name')->default('Standard Company Schedule');
            $table->json('work_days')->nullable();
            $table->time('work_start_time')->default('08:30:00');
            $table->time('work_end_time')->default('17:30:00');
            $table->decimal('standard_daily_hours', 4, 2)->default(9.00);
            $table->unsignedSmallInteger('flexible_arrival_window_minutes')->default(60);
            $table->date('ramadan_start_date')->nullable();
            $table->date('ramadan_end_date')->nullable();
            $table->time('ramadan_work_start_time')->nullable();
            $table->time('ramadan_work_end_time')->nullable();
            $table->decimal('ramadan_daily_hours', 4, 2)->nullable();
            $table->time('permission_submission_deadline')->default('15:00:00');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_work_schedules');
    }
};
