<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The field definitions (D-032), the changes waiting for review and the history they become (D-033), and the
 * periodic reviews of each cycle (D-036).
 */
return new class extends Migration
{
    public function up(): void
    {
        // «دليل الحقول» as data: what counts for completeness and the cycle are editable; the rest is the code's.
        Schema::create('field_definitions', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->string('label_ar', 200);
            $table->string('section_ar', 100);
            $table->string('workbook_column', 20);
            $table->string('type', 20);
            $table->string('list_key', 50)->nullable();
            $table->string('cycle', 20);
            $table->boolean('counts_for_completeness');
            $table->boolean('identity');
            $table->integer('sort_order');
            $table->foreignUuid('updated_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE field_definitions ADD CONSTRAINT field_definitions_cycle CHECK (cycle IN ('once', 'monthly', 'quarterly', 'semi_annual', 'annual'))");

        // One submission: a new employee, some changes, or the confirmation of a cycle (with changes or none).
        Schema::create('employee_change_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('cycle', 20)->nullable();
            $table->string('period', 10)->nullable();
            $table->string('status', 20)->default('pending');
            // It changes a field that identifies the person: only `identity.review` approves it (D-034).
            $table->boolean('needs_identity_review')->default(false);
            $table->text('note')->nullable();
            $table->foreignUuid('submitted_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            // Approved at once because its author reviews (D-023): reviewed_by is the author.
            $table->boolean('self_approved')->default(false);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'submitted_at']);
            $table->index(['employee_id', 'submitted_at']);
            $table->index(['submitted_by', 'submitted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_change_sets
                ADD CONSTRAINT employee_change_sets_kind CHECK (kind IN ('create', 'update', 'review')),
                ADD CONSTRAINT employee_change_sets_status CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn')),
                ADD CONSTRAINT employee_change_sets_cycle CHECK (
                    (kind = 'review') = (cycle IS NOT NULL)
                    AND (cycle IS NULL OR cycle IN ('monthly', 'quarterly', 'semi_annual', 'annual'))
                    AND ((cycle IS NULL) = (period IS NULL))
                ),
                ADD CONSTRAINT employee_change_sets_reviewed CHECK (
                    (status = 'pending' AND reviewed_at IS NULL AND reviewed_by IS NULL)
                    OR (status = 'withdrawn' AND reviewed_at IS NOT NULL)
                    OR (status IN ('approved', 'rejected') AND reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL)
                ),
                ADD CONSTRAINT employee_change_sets_rejection_reason CHECK (status <> 'rejected' OR btrim(coalesce(review_note, '')) <> ''),
                ADD CONSTRAINT employee_change_sets_four_eyes CHECK (
                    status <> 'approved' OR self_approved = (reviewed_by = submitted_by)
                )
            SQL);

        // A cycle is confirmed once per period: one confirmation waiting or done.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employee_change_sets_one_review_per_period
                ON employee_change_sets (employee_id, cycle, period)
                WHERE kind = 'review' AND status IN ('pending', 'approved')
            SQL);

        Schema::create('employee_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('change_set_id')->constrained('employee_change_sets')->restrictOnDelete();
            $table->string('field', 80);
            $table->foreign('field')->references('key')->on('field_definitions')->restrictOnDelete();
            // The approved value when submitted, and the new one: scalars as JSON (null, string, number, boolean).
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();

            $table->unique(['change_set_id', 'field']);
            $table->index('field');
        });

        // The confirmation of a cycle for a period: what the workbook's «آخر مراجعة» dates were (D-036).
        Schema::create('employee_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('cycle', 20);
            $table->string('period', 10);
            $table->date('reviewed_on');
            $table->foreignUuid('change_set_id')->unique()->constrained('employee_change_sets')->restrictOnDelete();
            $table->foreignUuid('confirmed_by')->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['employee_id', 'cycle', 'period']);
            $table->index(['employee_id', 'cycle', 'reviewed_on']);
        });

        DB::statement("ALTER TABLE employee_reviews ADD CONSTRAINT employee_reviews_cycle CHECK (cycle IN ('monthly', 'quarterly', 'semi_annual', 'annual'))");

        // The history is never rewritten (D-033): items and reviews are append-only; a change set changes only
        // once, from pending to its outcome.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION employee_history_refuse_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER employee_change_items_append_only BEFORE UPDATE OR DELETE ON employee_change_items
                FOR EACH ROW EXECUTE FUNCTION employee_history_refuse_change();
            CREATE TRIGGER employee_change_items_no_truncate BEFORE TRUNCATE ON employee_change_items
                FOR EACH STATEMENT EXECUTE FUNCTION employee_history_refuse_change();
            CREATE TRIGGER employee_reviews_append_only BEFORE UPDATE OR DELETE ON employee_reviews
                FOR EACH ROW EXECUTE FUNCTION employee_history_refuse_change();
            CREATE TRIGGER employee_reviews_no_truncate BEFORE TRUNCATE ON employee_reviews
                FOR EACH STATEMENT EXECUTE FUNCTION employee_history_refuse_change();
            CREATE TRIGGER employee_change_sets_no_delete BEFORE DELETE ON employee_change_sets
                FOR EACH ROW EXECUTE FUNCTION employee_history_refuse_change();
            CREATE TRIGGER employee_change_sets_no_truncate BEFORE TRUNCATE ON employee_change_sets
                FOR EACH STATEMENT EXECUTE FUNCTION employee_history_refuse_change();

            CREATE OR REPLACE FUNCTION employee_change_sets_decided_once() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'employee_change_sets: a decided change set is not changed' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.employee_id <> OLD.employee_id OR NEW.kind <> OLD.kind OR NEW.submitted_by <> OLD.submitted_by
                   OR NEW.submitted_at <> OLD.submitted_at OR NEW.cycle IS DISTINCT FROM OLD.cycle
                   OR NEW.period IS DISTINCT FROM OLD.period OR NEW.needs_identity_review <> OLD.needs_identity_review THEN
                    RAISE EXCEPTION 'employee_change_sets: only the decision is recorded' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_change_sets_decided_once BEFORE UPDATE ON employee_change_sets
                FOR EACH ROW EXECUTE FUNCTION employee_change_sets_decided_once();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_reviews');
        Schema::dropIfExists('employee_change_items');
        Schema::dropIfExists('employee_change_sets');
        Schema::dropIfExists('field_definitions');
        DB::unprepared('DROP FUNCTION IF EXISTS employee_change_sets_decided_once(); DROP FUNCTION IF EXISTS employee_history_refuse_change();');
    }
};
