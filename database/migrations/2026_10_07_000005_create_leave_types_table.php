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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->decimal('default_days_per_year', 5, 2)->default(0.00);
            $table->boolean('is_paid')->default(true);
            $table->boolean('deducts_from_annual_balance')->default(false);
            $table->decimal('max_days_per_year', 5, 2)->nullable();
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->boolean('requires_medical_certificate')->default(false);
            $table->boolean('carries_forward')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
