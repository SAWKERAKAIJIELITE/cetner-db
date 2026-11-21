<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trusted devices, single-use login challenges and Sanctum's token table with uuid owners
 * and the device a token was issued to (D-013).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label', 150);
            $table->text('public_key');
            $table->char('fingerprint', 64)->unique();
            $table->macAddress('reported_mac'); // ->unique(); temporarily disabled for frontend ask.
            $table->string('os_info', 255);
            $table->enum('status', ['pending', 'approved', 'revoked'])->default('pending');
            $table->foreignUuid('staff_user_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE staff_devices
                ADD CONSTRAINT staff_devices_fingerprint_hex CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT staff_devices_approved_fields CHECK (status <> 'approved' OR (approved_at IS NOT NULL AND staff_user_id IS NOT NULL)),
                ADD CONSTRAINT staff_devices_revoked_fields CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)
            SQL);

        // One approved device per staff user; a revoked device frees the slot and stays as history.
        DB::statement("CREATE UNIQUE INDEX staff_devices_one_approved_per_user ON staff_devices (staff_user_id) WHERE status = 'approved'");

        Schema::create('device_login_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Derived and short-lived: cascading with its device is the one exception to D-007.
            $table->foreignUuid('staff_device_id')->constrained('staff_devices')->cascadeOnDelete();
            $table->string('challenge', 128);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('expires_at');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->foreignUuid('staff_device_id')->nullable()->constrained('staff_devices')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('device_login_challenges');
        Schema::dropIfExists('staff_devices');
    }
};
