<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importing the owner's workbooks (D-053): an upload is staged row by row with what is wrong with each, reviewed,
 * then applied whole or not at all. The changes it makes are the file's and the registers' own change sets, each
 * marked with its import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('file_name', 255);
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->string('duplicates', 10);
            $table->string('status', 20)->default('pending');
            // Per sheet: its kind, header row, matched / unmatched / ignored columns, and its rows' counts.
            $table->jsonb('sheets')->default('[]');
            $table->jsonb('counts')->default('{}');
            $table->foreignUuid('uploaded_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->foreignUuid('decided_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('applied_at')->nullable();
            // What it made: files created and updated, records added and corrected, reviews.
            $table->jsonb('applied')->nullable();
            // Why it failed: the sheet, the line, the message.
            $table->jsonb('failure')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'uploaded_at']);
            $table->index('sha256');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE imports
                ADD CONSTRAINT imports_status CHECK (status IN ('pending', 'applying', 'approved', 'failed', 'rejected', 'cancelled')),
                ADD CONSTRAINT imports_duplicates CHECK (duplicates IN ('skip', 'update')),
                ADD CONSTRAINT imports_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT imports_size CHECK (size_bytes > 0),
                ADD CONSTRAINT imports_decided CHECK ((status = 'pending') = (decided_at IS NULL) AND (decided_at IS NULL) = (decided_by IS NULL)),
                ADD CONSTRAINT imports_rejection_reason CHECK (status <> 'rejected' OR btrim(coalesce(decision_note, '')) <> ''),
                ADD CONSTRAINT imports_applied CHECK ((status = 'approved') = (applied_at IS NOT NULL))
            SQL);

        // One approved import of the same file: importing it twice would double every record.
        DB::statement("CREATE UNIQUE INDEX imports_one_approved_file ON imports (sha256) WHERE status IN ('applying', 'approved')");

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('import_id')->constrained('imports')->restrictOnDelete();
            $table->string('sheet', 20);
            $table->integer('line');
            $table->string('status', 10);
            // create / update / skip for a file; add / correct / skip for a record.
            $table->string('action', 10);
            $table->string('personal_number', 50)->nullable();
            $table->uuid('employee_id')->nullable();
            $table->uuid('record_id')->nullable();
            // The row as read (column title => text), the values it gives (field => normal value), what it changes.
            $table->jsonb('cells');
            $table->jsonb('values');
            $table->jsonb('changes')->nullable();
            // [{level: error|warning|info, field, message}]
            $table->jsonb('issues');

            $table->unique(['import_id', 'sheet', 'line']);
            $table->index(['import_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_rows
                ADD CONSTRAINT import_rows_sheet CHECK (sheet IN ('employees', 'movements', 'attendance', 'conduct', 'debts')),
                ADD CONSTRAINT import_rows_status CHECK (status IN ('valid', 'review', 'invalid', 'excluded')),
                ADD CONSTRAINT import_rows_action CHECK (action IN ('create', 'update', 'add', 'correct', 'skip')),
                ADD CONSTRAINT import_rows_line CHECK (line > 0)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION imports_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows are never deleted', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_TABLE_NAME = 'imports' THEN
                    IF NEW.sha256 <> OLD.sha256 OR NEW.file_name <> OLD.file_name OR NEW.uploaded_by <> OLD.uploaded_by
                       OR NEW.uploaded_at <> OLD.uploaded_at OR NEW.duplicates <> OLD.duplicates THEN
                        RAISE EXCEPTION 'imports: what was uploaded never changes' USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.status NOT IN ('pending', 'applying') AND NEW.status <> OLD.status THEN
                        RAISE EXCEPTION 'imports: a decided import is not decided again' USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.status = 'applying' AND NEW.status NOT IN ('applying', 'approved', 'failed') THEN
                        RAISE EXCEPTION 'imports: an import being applied ends approved or failed' USING ERRCODE = 'restrict_violation';
                    END IF;
                ELSE
                    -- A staged row only learns what it made (its employee or record).
                    IF NEW.import_id <> OLD.import_id OR NEW.sheet <> OLD.sheet OR NEW.line <> OLD.line OR NEW.status <> OLD.status
                       OR NEW.cells <> OLD.cells OR NEW.values <> OLD.values OR NEW.issues <> OLD.issues THEN
                        RAISE EXCEPTION 'import_rows: a staged row never changes' USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER imports_guard BEFORE UPDATE OR DELETE ON imports FOR EACH ROW EXECUTE FUNCTION imports_guard();
            CREATE TRIGGER import_rows_guard BEFORE UPDATE OR DELETE ON import_rows FOR EACH ROW EXECUTE FUNCTION imports_guard();
            CREATE TRIGGER imports_no_truncate BEFORE TRUNCATE ON imports FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();
            CREATE TRIGGER import_rows_no_truncate BEFORE TRUNCATE ON import_rows FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();
            SQL);

        // What an import made is marked with it, in the file's and the registers' history.
        Schema::table('employee_change_sets', function (Blueprint $table) {
            $table->foreignUuid('import_id')->nullable()->constrained('imports')->restrictOnDelete();
            $table->index('import_id');
        });
        Schema::table('record_change_sets', function (Blueprint $table) {
            $table->foreignUuid('import_id')->nullable()->constrained('imports')->restrictOnDelete();
            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::table('record_change_sets', fn (Blueprint $t) => $t->dropConstrainedForeignId('import_id'));
        Schema::table('employee_change_sets', fn (Blueprint $t) => $t->dropConstrainedForeignId('import_id'));
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
        DB::unprepared('DROP FUNCTION IF EXISTS imports_guard()');
    }
};
