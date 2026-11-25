<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four records of the workbook (D-040): «سجل الحركات», «سجل الدوام الشهري», «سجل السلوك الوظيفي», «سجل الديون»,
 * and the changes that write them, reviewed like the file's (D-041).
 *
 * A record is never deleted: removing one marks it, and the change that removed it is the history. A record is
 * written only by an approved change; its id is chosen when the change is submitted, so the history can name it
 * before it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
        };

        $closing = function (Blueprint $table): void {
            $table->text('notes')->nullable();
            // Who entered it (the author of the change that added it) and who approved that change.
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignUuid('deleted_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
        };

        Schema::create('employee_movements', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->date('occurred_on');
            $table->foreignId('movement_type_id')->constrained('movement_types')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('identified_by', 200)->nullable();
            $table->string('entity', 200)->nullable();
            $table->decimal('course_mark', 7, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->integer('days')->nullable();
            $table->string('status', 100)->nullable();
            $closing($table);

            $table->index(['employee_id', 'occurred_on']);
            $table->index(['movement_type_id', 'occurred_on']);
        });

        Schema::create('employee_attendance', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->smallInteger('planned_days');
            $table->smallInteger('attended_days');
            $table->text('exceptional_skills')->nullable();
            $closing($table);

            $table->index(['year', 'month']);
        });

        Schema::create('employee_conduct_records', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->date('occurred_on');
            $table->foreignId('conduct_type_id')->constrained('conduct_types')->restrictOnDelete();
            $table->text('subject')->nullable();
            $table->string('reported_by', 200)->nullable();
            $table->text('decision')->nullable();
            $table->string('decision_number', 100)->nullable();
            $table->date('decision_on')->nullable();
            $table->foreignId('conduct_status_id')->nullable()->constrained('conduct_statuses')->restrictOnDelete();
            $table->string('document_link', 500)->nullable();
            $closing($table);

            $table->index(['employee_id', 'occurred_on']);
            $table->index(['conduct_type_id', 'occurred_on']);
        });

        Schema::create('employee_debts', function (Blueprint $table) use ($common, $closing) {
            $common($table);
            $table->string('creditor', 200)->nullable();
            $table->foreignId('debt_type_id')->nullable()->constrained('debt_types')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->date('incurred_on')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->decimal('monthly_installment', 14, 2)->nullable();
            $table->date('due_on')->nullable();
            $closing($table);

            $table->index(['employee_id']);
            $table->index(['due_on']);
        });

        // Computed by the database, never written: the absent days and what is left to pay (the workbook's formulas).
        DB::statement(<<<'SQL'
            ALTER TABLE employee_attendance
                ADD COLUMN absent_days smallint GENERATED ALWAYS AS (planned_days - attended_days) STORED,
                ADD CONSTRAINT employee_attendance_year CHECK (year BETWEEN 2000 AND 2100),
                ADD CONSTRAINT employee_attendance_month CHECK (month BETWEEN 1 AND 12),
                ADD CONSTRAINT employee_attendance_planned CHECK (
                    planned_days BETWEEN 0 AND extract(day FROM (make_date(year, month, 1) + interval '1 month - 1 day'))
                ),
                ADD CONSTRAINT employee_attendance_attended CHECK (attended_days BETWEEN 0 AND planned_days)
            SQL);

        // One month's attendance per employee.
        DB::statement('CREATE UNIQUE INDEX employee_attendance_one_per_month ON employee_attendance (employee_id, year, month) WHERE deleted_at IS NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE employee_debts
                ADD COLUMN remaining_amount numeric(14, 2) GENERATED ALWAYS AS (amount - coalesce(paid_amount, 0)) STORED,
                ADD CONSTRAINT employee_debts_amount CHECK (amount > 0),
                ADD CONSTRAINT employee_debts_paid CHECK (paid_amount IS NULL OR paid_amount BETWEEN 0 AND amount),
                ADD CONSTRAINT employee_debts_installment CHECK (monthly_installment IS NULL OR monthly_installment >= 0),
                ADD CONSTRAINT employee_debts_creditor_not_blank CHECK (creditor IS NULL OR btrim(creditor) <> '')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employee_movements
                ADD CONSTRAINT employee_movements_range CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on),
                ADD CONSTRAINT employee_movements_days CHECK (days IS NULL OR days BETWEEN 0 AND 3660),
                ADD CONSTRAINT employee_movements_amount CHECK (amount IS NULL OR amount >= 0),
                ADD CONSTRAINT employee_movements_mark CHECK (course_mark IS NULL OR course_mark BETWEEN 0 AND 1000)
            SQL);

        foreach (['employee_movements', 'employee_attendance', 'employee_conduct_records', 'employee_debts'] as $name) {
            DB::statement(<<<SQL
                ALTER TABLE {$name}
                    ADD CONSTRAINT {$name}_deleted CHECK ((deleted_at IS NULL) = (deleted_by IS NULL))
                SQL);
        }

        // One submission of one kind of record: one change, or a batch (a month's attendance for a unit, say).
        Schema::create('record_change_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('record_type', 20);
            $table->string('status', 20)->default('pending');
            $table->integer('items_count');
            $table->text('note')->nullable();
            $table->foreignUuid('submitted_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->boolean('self_approved')->default(false);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'submitted_at']);
            $table->index(['submitted_by', 'submitted_at']);
            $table->index(['record_type', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE record_change_sets
                ADD CONSTRAINT record_change_sets_type CHECK (record_type IN ('movements', 'attendance', 'conduct', 'debts')),
                ADD CONSTRAINT record_change_sets_status CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn')),
                ADD CONSTRAINT record_change_sets_items CHECK (items_count > 0),
                ADD CONSTRAINT record_change_sets_reviewed CHECK (
                    (status = 'pending' AND reviewed_at IS NULL AND reviewed_by IS NULL)
                    OR (status = 'withdrawn' AND reviewed_at IS NOT NULL)
                    OR (status IN ('approved', 'rejected') AND reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL)
                ),
                ADD CONSTRAINT record_change_sets_rejection_reason CHECK (status <> 'rejected' OR btrim(coalesce(review_note, '')) <> ''),
                ADD CONSTRAINT record_change_sets_four_eyes CHECK (status <> 'approved' OR self_approved = (reviewed_by = submitted_by))
            SQL);

        // Each change: a record added, changed or removed. The approved values beside the new ones, only the fields
        // that change; a removal keeps the whole record as it was.
        Schema::create('record_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('change_set_id')->constrained('record_change_sets')->restrictOnDelete();
            $table->integer('position');
            $table->string('action', 10);
            $table->uuid('record_id');
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->unique(['change_set_id', 'position']);
            $table->unique(['change_set_id', 'record_id']);
            $table->index('record_id');
            $table->index('employee_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE record_change_items
                ADD CONSTRAINT record_change_items_action CHECK (
                    (action = 'create' AND old_values IS NULL AND jsonb_typeof(new_values) = 'object')
                    OR (action = 'update' AND jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object')
                    OR (action = 'delete' AND jsonb_typeof(old_values) = 'object' AND new_values IS NULL)
                )
            SQL);

        // A pending month of attendance is that month's: found fast when another is entered.
        DB::statement(<<<'SQL'
            CREATE INDEX record_change_items_attendance_month ON record_change_items
                (employee_id, (new_values->>'year'), (new_values->>'month'))
                WHERE action = 'create'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION records_refuse_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER record_change_items_append_only BEFORE UPDATE OR DELETE ON record_change_items
                FOR EACH ROW EXECUTE FUNCTION records_refuse_change();
            CREATE TRIGGER record_change_items_no_truncate BEFORE TRUNCATE ON record_change_items
                FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();
            CREATE TRIGGER record_change_sets_no_delete BEFORE DELETE ON record_change_sets
                FOR EACH ROW EXECUTE FUNCTION records_refuse_change();
            CREATE TRIGGER record_change_sets_no_truncate BEFORE TRUNCATE ON record_change_sets
                FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();

            CREATE OR REPLACE FUNCTION record_change_sets_decided_once() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'record_change_sets: a decided change set is not changed' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.record_type <> OLD.record_type OR NEW.submitted_by <> OLD.submitted_by
                   OR NEW.submitted_at <> OLD.submitted_at OR NEW.items_count <> OLD.items_count THEN
                    RAISE EXCEPTION 'record_change_sets: only the decision is recorded' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER record_change_sets_decided_once BEFORE UPDATE ON record_change_sets
                FOR EACH ROW EXECUTE FUNCTION record_change_sets_decided_once();

            -- A record is never deleted, a removed one never changes, and whose it is and who entered it stay.
            CREATE OR REPLACE FUNCTION records_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '%: a record is removed by marking it, never deleted', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.deleted_at IS NOT NULL THEN
                    RAISE EXCEPTION '%: a removed record is not changed', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.id <> OLD.id OR NEW.employee_id <> OLD.employee_id OR NEW.created_by <> OLD.created_by
                   OR NEW.approved_by <> OLD.approved_by OR NEW.created_at <> OLD.created_at THEN
                    RAISE EXCEPTION '%: whose record it is and who entered it are not changed', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            -- A month's attendance stays that month's: a wrong month is removed and entered again (D-042).
            CREATE OR REPLACE FUNCTION employee_attendance_period_fixed() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.year <> OLD.year OR NEW.month <> OLD.month THEN
                    RAISE EXCEPTION 'employee_attendance: the month of an attendance record is not changed' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_attendance_period_fixed BEFORE UPDATE ON employee_attendance
                FOR EACH ROW EXECUTE FUNCTION employee_attendance_period_fixed();
            SQL);

        foreach (['employee_movements', 'employee_attendance', 'employee_conduct_records', 'employee_debts'] as $name) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}_guard BEFORE UPDATE OR DELETE ON {$name}
                    FOR EACH ROW EXECUTE FUNCTION records_guard();
                CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name}
                    FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('record_change_items');
        Schema::dropIfExists('record_change_sets');
        Schema::dropIfExists('employee_debts');
        Schema::dropIfExists('employee_conduct_records');
        Schema::dropIfExists('employee_attendance');
        Schema::dropIfExists('employee_movements');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS employee_attendance_period_fixed();
            DROP FUNCTION IF EXISTS records_guard();
            DROP FUNCTION IF EXISTS record_change_sets_decided_once();
            DROP FUNCTION IF EXISTS records_refuse_change();
            SQL);
    }
};
