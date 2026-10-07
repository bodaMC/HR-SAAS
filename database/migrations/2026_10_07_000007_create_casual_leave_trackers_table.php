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
        Schema::create('casual_leave_trackers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->unsignedSmallInteger('year')->index();
            $table->decimal('max_quota_days', 4, 2)->default(7.00);
            $table->decimal('used_quota_days', 4, 2)->default(0.00);
            $table->decimal('pending_quota_days', 4, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('casual_leave_trackers');
    }
};
