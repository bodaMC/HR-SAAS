<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3.2 RBAC. Additive only: three new tables and one extra unique index on
 * users (id, company_id) so assignments can carry a composite tenant FK.
 * No existing row is changed and no account is elevated by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'users_id_company_id_unique');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('restrict');
            $table->string('key', 80);
            $table->string('name', 100);
            $table->string('name_ar', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_protected')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->unique(['id', 'company_id'], 'roles_id_company_id_unique');
        });

        // Role names are unique per tenant, case-insensitively (database-level guarantee in addition to the tenant lock).
        DB::statement('CREATE UNIQUE INDEX roles_company_lower_name_unique ON roles (company_id, lower(name))');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->string('permission', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['role_id', 'permission']);
        });

        // Explicit delegation boundary of a role: permissions its holders may grant to others.
        Schema::create('role_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->string('permission', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['role_id', 'permission']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Set only for singleton system roles (System Super Admin): the partial unique index below
            // makes "one holder per tenant" a database invariant, not only an application check.
            $table->string('exclusive_key', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'role_id']);
            // Tenant consistency: user and role must belong to the same company.
            $table->foreign(['user_id', 'company_id'])->references(['id', 'company_id'])->on('users')->onDelete('cascade');
            $table->foreign(['role_id', 'company_id'])->references(['id', 'company_id'])->on('roles')->onDelete('restrict');
        });
        DB::statement('CREATE UNIQUE INDEX user_roles_exclusive_per_company ON user_roles (company_id, exclusive_key) WHERE exclusive_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_delegations');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_id_company_id_unique');
        });
    }
};
