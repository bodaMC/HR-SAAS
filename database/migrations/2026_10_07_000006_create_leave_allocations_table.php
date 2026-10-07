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
        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->foreignId('leave_type_id')->constrained('leave_types')->onDelete('restrict');
            $table->unsignedSmallInteger('year')->index();
            $table->decimal('statutory_entitlement_days', 5, 2)->default(0.00);
            $table->decimal('pro_rata_factor', 5, 4)->default(1.0000);
            $table->decimal('allocated_days', 5, 2)->default(0.00);
            $table->decimal('carried_over_days', 5, 2)->default(0.00);
            $table->decimal('converted_from_compensatory_days', 5, 2)->default(0.00);
            $table->decimal('used_days', 5, 2)->default(0.00);
            $table->decimal('pending_days', 5, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_id', 'leave_type_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_allocations');
    }
};
