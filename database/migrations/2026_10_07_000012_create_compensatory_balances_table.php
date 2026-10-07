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
        Schema::create('compensatory_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('restrict');
            $table->decimal('total_earned_hours', 6, 2)->default(0.00);
            $table->decimal('used_as_permission_hours', 6, 2)->default(0.00);
            $table->decimal('converted_to_leave_hours', 6, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensatory_balances');
    }
};
