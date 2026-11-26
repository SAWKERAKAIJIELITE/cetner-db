<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A movement updates the employee's file (owner, 25 Nov; D-048): the movement says what the file becomes (a new
 * unit, status, position or title; a date), and once approved and due, the file takes it through a change of its
 * own kind, «تطبيق حركة», in the file's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_movements', function (Blueprint $table) {
            $table->foreignId('new_org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->foreignId('new_job_status_id')->nullable()->constrained('job_statuses')->restrictOnDelete();
            $table->foreignId('new_position_level_id')->nullable()->constrained('position_levels')->restrictOnDelete();
            $table->foreignId('new_job_title_id')->nullable()->constrained('job_titles')->restrictOnDelete();
            // When the file took it, and the file's change that did (none when nothing differed).
            $table->timestampTz('applied_to_file_at')->nullable();
            $table->foreignUuid('file_change_set_id')->nullable()->constrained('employee_change_sets')->restrictOnDelete();

            $table->index(['applied_to_file_at', 'occurred_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_movements
                ADD CONSTRAINT employee_movements_applied CHECK (file_change_set_id IS NULL OR applied_to_file_at IS NOT NULL)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employee_change_sets
                DROP CONSTRAINT employee_change_sets_kind,
                ADD CONSTRAINT employee_change_sets_kind CHECK (kind IN ('create', 'update', 'review', 'movement'))
            SQL);

        // Once the file took a movement, that stays.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION employee_movements_applied_once() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.applied_to_file_at IS NOT NULL AND (NEW.applied_to_file_at IS DISTINCT FROM OLD.applied_to_file_at
                   OR NEW.file_change_set_id IS DISTINCT FROM OLD.file_change_set_id) THEN
                    RAISE EXCEPTION 'employee_movements: a movement the file took stays taken' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_movements_applied_once BEFORE UPDATE ON employee_movements
                FOR EACH ROW EXECUTE FUNCTION employee_movements_applied_once();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS employee_movements_applied_once ON employee_movements;
            DROP FUNCTION IF EXISTS employee_movements_applied_once();
            ALTER TABLE employee_change_sets DROP CONSTRAINT employee_change_sets_kind,
                ADD CONSTRAINT employee_change_sets_kind CHECK (kind IN ('create', 'update', 'review'));
            ALTER TABLE employee_movements DROP CONSTRAINT employee_movements_applied;
            SQL);

        Schema::table('employee_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('file_change_set_id');
            $table->dropColumn('applied_to_file_at');
            $table->dropConstrainedForeignId('new_job_title_id');
            $table->dropConstrainedForeignId('new_position_level_id');
            $table->dropConstrainedForeignId('new_job_status_id');
            $table->dropConstrainedForeignId('new_org_unit_id');
        });
    }
};
