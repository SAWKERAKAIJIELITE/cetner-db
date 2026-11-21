<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff users, their one role, the permission catalogue (D-006, D-015, D-019).
 * Every foreign key is RESTRICT; people are deactivated, never deleted (D-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('label_ar', 100);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('label_ar', 150);
            // It only shows data: the only kind the general manager's role takes.
            $table->boolean('read_only')->default(false);
            $table->timestampsTz();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('staff_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('personal_number', 50)->unique();
            $table->string('username', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->uuid('deactivated_by')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->uuid('role_assigned_by')->nullable();
            $table->timestampTz('role_assigned_at')->nullable();
            $table->timestampsTz();

            $table->index('role_id');
        });

        Schema::table('staff_users', function (Blueprint $table) {
            $table->foreign('deactivated_by')->references('id')->on('staff_users')->restrictOnDelete();
            $table->foreign('role_assigned_by')->references('id')->on('staff_users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE staff_users
                ADD CONSTRAINT staff_users_username_format CHECK (username ~ '^[a-z._-]{3,50}$'),
                ADD CONSTRAINT staff_users_personal_number_format CHECK (personal_number ~ '^[0-9]{4,50}$'),
                ADD CONSTRAINT staff_users_first_name_format CHECK (char_length(btrim(first_name)) >= 3 AND first_name !~ '[0-9٠-٩]'),
                ADD CONSTRAINT staff_users_last_name_format CHECK (char_length(btrim(last_name)) >= 3 AND last_name !~ '[0-9٠-٩]'),
                ADD CONSTRAINT staff_users_deactivation CHECK (
                    (is_active AND deactivated_at IS NULL AND deactivated_by IS NULL AND deactivation_reason IS NULL)
                    OR (NOT is_active AND deactivated_at IS NOT NULL AND deactivation_reason IS NOT NULL AND btrim(deactivation_reason) <> '')
                ),
                ADD CONSTRAINT staff_users_role_assignment CHECK (role_id IS NOT NULL OR (role_assigned_by IS NULL AND role_assigned_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
