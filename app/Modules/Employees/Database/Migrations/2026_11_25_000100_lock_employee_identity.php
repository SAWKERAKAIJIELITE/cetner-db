<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The identity lock (D-034, owner 24 Nov): once the administrator approves a file's identity, its six identity
 * fields (الرقم الذاتي, الرقم الوطني, الاسم, اسم الأب, اسم الأم, العائلة) are never changed again, by anyone. An
 * identity field still empty may be filled once. The database refuses the change too, whatever the code; a lock
 * is never undone. `identity_hash` is the keyed fingerprint of the locked identity (D-038).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestampTz('identity_locked_at')->nullable();
            $table->foreignUuid('identity_locked_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->char('identity_hash', 64)->nullable();

            $table->index('identity_locked_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_identity_lock_complete CHECK (
                    (identity_locked_at IS NULL AND identity_locked_by IS NULL AND identity_hash IS NULL)
                    OR (identity_locked_at IS NOT NULL AND identity_locked_by IS NOT NULL AND identity_hash ~ '^[0-9a-f]{64}$')
                ),
                ADD CONSTRAINT employees_identity_lock_active_only CHECK (identity_locked_at IS NULL OR file_status = 'active')
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION employees_identity_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                filled boolean;
            BEGIN
                IF OLD.identity_locked_at IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.identity_locked_at IS DISTINCT FROM OLD.identity_locked_at
                   OR NEW.identity_locked_by IS DISTINCT FROM OLD.identity_locked_by THEN
                    RAISE EXCEPTION 'employees: an identity lock is never undone' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.personal_number IS NOT NULL AND NEW.personal_number IS DISTINCT FROM OLD.personal_number)
                   OR (OLD.national_number IS NOT NULL AND NEW.national_number IS DISTINCT FROM OLD.national_number)
                   OR (OLD.first_name IS NOT NULL AND NEW.first_name IS DISTINCT FROM OLD.first_name)
                   OR (OLD.father_name IS NOT NULL AND NEW.father_name IS DISTINCT FROM OLD.father_name)
                   OR (OLD.mother_name IS NOT NULL AND NEW.mother_name IS DISTINCT FROM OLD.mother_name)
                   OR (OLD.family_name IS NOT NULL AND NEW.family_name IS DISTINCT FROM OLD.family_name) THEN
                    RAISE EXCEPTION 'employees: the identity of this file is locked' USING ERRCODE = 'restrict_violation';
                END IF;

                -- The fingerprint changes only with an empty identity field filled for the first time.
                filled := (OLD.personal_number IS NULL AND NEW.personal_number IS NOT NULL)
                       OR (OLD.national_number IS NULL AND NEW.national_number IS NOT NULL)
                       OR (OLD.first_name IS NULL AND NEW.first_name IS NOT NULL)
                       OR (OLD.father_name IS NULL AND NEW.father_name IS NOT NULL)
                       OR (OLD.mother_name IS NULL AND NEW.mother_name IS NOT NULL)
                       OR (OLD.family_name IS NULL AND NEW.family_name IS NOT NULL);

                IF NEW.identity_hash IS DISTINCT FROM OLD.identity_hash AND NOT filled THEN
                    RAISE EXCEPTION 'employees: the identity fingerprint follows the identity only' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employees_identity_locked
                BEFORE UPDATE ON employees
                FOR EACH ROW EXECUTE FUNCTION employees_identity_locked();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS employees_identity_locked ON employees; DROP FUNCTION IF EXISTS employees_identity_locked();');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_locked_by');
            $table->dropColumn(['identity_locked_at', 'identity_hash']);
        });
    }
};
