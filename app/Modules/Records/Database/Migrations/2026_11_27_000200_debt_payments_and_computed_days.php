<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1 (owner, 25 Nov):
 * - Debt payments are entries of their own (D-045): «تسجيل دفعة مالية», never edited away. A debt's payments
 *   total is the database's (a trigger keeps it), and what is left is the amount less the amount paid when the
 *   debt was entered less its payments.
 * - A movement's days are calendar days from «من تاريخ» to «إلى تاريخ», both included, computed (D-042).
 * - The attended days of a month may have a half (the workbook takes any number up to the planned days).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Days: from the dates, never typed. A period is under 400 days (the workbook's limit on the days).
        DB::statement(<<<'SQL'
            ALTER TABLE employee_movements
                DROP COLUMN days,
                ADD COLUMN days integer GENERATED ALWAYS AS (CASE WHEN starts_on IS NOT NULL AND ends_on IS NOT NULL THEN ends_on - starts_on + 1 END) STORED,
                ADD CONSTRAINT employee_movements_period CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on - starts_on < 400),
                ADD CONSTRAINT employee_movements_period_whole CHECK ((starts_on IS NULL) = (ends_on IS NULL))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employee_attendance
                DROP COLUMN absent_days,
                ALTER COLUMN attended_days TYPE numeric(4, 1),
                ADD COLUMN absent_days numeric(4, 1) GENERATED ALWAYS AS (planned_days - attended_days) STORED
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employee_debts
                DROP CONSTRAINT employee_debts_paid,
                DROP COLUMN remaining_amount,
                ADD COLUMN payments_total numeric(14, 2) NOT NULL DEFAULT 0,
                ADD COLUMN remaining_amount numeric(14, 2) GENERATED ALWAYS AS (amount - coalesce(paid_amount, 0) - payments_total) STORED,
                ADD CONSTRAINT employee_debts_paid CHECK (paid_amount IS NULL OR paid_amount >= 0),
                ADD CONSTRAINT employee_debts_not_overpaid CHECK (coalesce(paid_amount, 0) + payments_total <= amount)
            SQL);

        Schema::create('employee_debt_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('debt_id')->constrained('employee_debts')->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignUuid('deleted_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['debt_id', 'paid_on']);
            $table->index(['employee_id', 'paid_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_debt_payments
                ADD CONSTRAINT employee_debt_payments_amount CHECK (amount > 0),
                ADD CONSTRAINT employee_debt_payments_deleted CHECK ((deleted_at IS NULL) = (deleted_by IS NULL))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE record_change_sets
                DROP CONSTRAINT record_change_sets_type,
                ADD CONSTRAINT record_change_sets_type CHECK (record_type IN ('movements', 'attendance', 'conduct', 'debts', 'payments'))
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER employee_debt_payments_guard BEFORE UPDATE OR DELETE ON employee_debt_payments
                FOR EACH ROW EXECUTE FUNCTION records_guard();
            CREATE TRIGGER employee_debt_payments_no_truncate BEFORE TRUNCATE ON employee_debt_payments
                FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();

            -- A payment is never edited: only marked removed. It is the debt's, of the debt's employee.
            CREATE OR REPLACE FUNCTION employee_debt_payments_rules() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NOT EXISTS (SELECT 1 FROM employee_debts d WHERE d.id = NEW.debt_id AND d.employee_id = NEW.employee_id AND d.deleted_at IS NULL) THEN
                        RAISE EXCEPTION 'employee_debt_payments: a payment is of a kept debt of the same employee' USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.debt_id <> OLD.debt_id OR NEW.paid_on <> OLD.paid_on OR NEW.amount <> OLD.amount
                      OR NEW.payment_method_id <> OLD.payment_method_id OR NEW.notes IS DISTINCT FROM OLD.notes THEN
                    RAISE EXCEPTION 'employee_debt_payments: a payment is never edited, only removed' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_debt_payments_rules BEFORE INSERT OR UPDATE ON employee_debt_payments
                FOR EACH ROW EXECUTE FUNCTION employee_debt_payments_rules();

            -- The debt's payments total, kept by the database: the kept payments' sum.
            CREATE OR REPLACE FUNCTION employee_debt_payments_total() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE employee_debts SET payments_total = (
                    SELECT coalesce(sum(amount), 0) FROM employee_debt_payments WHERE debt_id = NEW.debt_id AND deleted_at IS NULL
                ) WHERE id = NEW.debt_id;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER employee_debt_payments_total AFTER INSERT OR UPDATE ON employee_debt_payments
                FOR EACH ROW EXECUTE FUNCTION employee_debt_payments_total();

            -- A debt's payments total is the trigger's only.
            CREATE OR REPLACE FUNCTION employee_debts_payments_total_kept() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.payments_total IS DISTINCT FROM OLD.payments_total AND pg_trigger_depth() < 2 THEN
                    RAISE EXCEPTION 'employee_debts: the payments total is the payments'' sum' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_debts_payments_total_kept BEFORE UPDATE ON employee_debts
                FOR EACH ROW EXECUTE FUNCTION employee_debts_payments_total_kept();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS employee_debts_payments_total_kept ON employee_debts;
            DROP FUNCTION IF EXISTS employee_debts_payments_total_kept();
            SQL);
        Schema::dropIfExists('employee_debt_payments');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS employee_debt_payments_total();
            DROP FUNCTION IF EXISTS employee_debt_payments_rules();
            ALTER TABLE record_change_sets DROP CONSTRAINT record_change_sets_type,
                ADD CONSTRAINT record_change_sets_type CHECK (record_type IN ('movements', 'attendance', 'conduct', 'debts'));
            ALTER TABLE employee_debts DROP CONSTRAINT employee_debts_not_overpaid, DROP CONSTRAINT employee_debts_paid,
                DROP COLUMN remaining_amount, DROP COLUMN payments_total,
                ADD COLUMN remaining_amount numeric(14, 2) GENERATED ALWAYS AS (amount - coalesce(paid_amount, 0)) STORED,
                ADD CONSTRAINT employee_debts_paid CHECK (paid_amount IS NULL OR paid_amount BETWEEN 0 AND amount);
            ALTER TABLE employee_attendance DROP COLUMN absent_days, ALTER COLUMN attended_days TYPE smallint,
                ADD COLUMN absent_days smallint GENERATED ALWAYS AS (planned_days - attended_days) STORED;
            ALTER TABLE employee_movements DROP CONSTRAINT employee_movements_period_whole, DROP CONSTRAINT employee_movements_period,
                DROP COLUMN days, ADD COLUMN days integer;
            SQL);
    }
};
