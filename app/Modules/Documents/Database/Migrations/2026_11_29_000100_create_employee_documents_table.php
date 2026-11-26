<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employees' documents (D-049): every upload is a row, its file on the private documents disk. One current file
 * of each kind per employee (and one per conduct record); a newer approved upload supersedes it and the old one
 * stays in the history. Reviewed like the file (D-023). Never deleted; a refused, withdrawn or removed upload keeps
 * its row but not its content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('kind', 40);
            $table->foreignUuid('conduct_record_id')->nullable()->constrained('employee_conduct_records')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('disk', 40);
            $table->string('path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->integer('size_bytes');
            $table->char('sha256', 64);
            $table->text('note')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->boolean('self_approved')->default(false);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestampTz('content_purged_at')->nullable();
            $table->timestampsTz();

            $table->index(['employee_id', 'kind', 'uploaded_at']);
            $table->index(['status', 'uploaded_at']);
            $table->index('conduct_record_id');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('employee_documents')->restrictOnDelete();
        });

        $kinds = "'identity_copy', 'contract_terms', 'security_form', 'signature_form', 'course_certificates', 'portrait', "
            ."'family_statement', 'practical_certificate', 'graduation_certificate', 'contract_copy', 'cv', 'inquiry_record', "
            ."'sham_cash_copy', 'conduct_document'";

        DB::statement(<<<SQL
            ALTER TABLE employee_documents
                ADD CONSTRAINT employee_documents_kind CHECK (kind IN ({$kinds})),
                ADD CONSTRAINT employee_documents_conduct CHECK ((kind = 'conduct_document') = (conduct_record_id IS NOT NULL)),
                ADD CONSTRAINT employee_documents_status CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn')),
                ADD CONSTRAINT employee_documents_mime CHECK (mime IN ('application/pdf', 'image/jpeg', 'image/png', 'image/webp')),
                ADD CONSTRAINT employee_documents_size CHECK (size_bytes > 0 AND size_bytes <= 52428800),
                ADD CONSTRAINT employee_documents_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT employee_documents_reviewed CHECK (
                    (status = 'pending' AND reviewed_at IS NULL AND reviewed_by IS NULL)
                    OR (status = 'withdrawn' AND reviewed_at IS NOT NULL)
                    OR (status IN ('approved', 'rejected') AND reviewed_at IS NOT NULL AND reviewed_by IS NOT NULL)
                ),
                ADD CONSTRAINT employee_documents_rejection_reason CHECK (status <> 'rejected' OR btrim(coalesce(review_note, '')) <> ''),
                ADD CONSTRAINT employee_documents_four_eyes CHECK (status <> 'approved' OR self_approved = (reviewed_by = uploaded_by)),
                ADD CONSTRAINT employee_documents_superseded CHECK ((superseded_at IS NULL) = (superseded_by_id IS NULL) AND (superseded_at IS NULL OR status = 'approved')),
                ADD CONSTRAINT employee_documents_removed CHECK (
                    (removed_at IS NULL AND removed_by IS NULL AND removal_reason IS NULL)
                    OR (removed_at IS NOT NULL AND removed_by IS NOT NULL AND btrim(coalesce(removal_reason, '')) <> '' AND status = 'approved')
                ),
                ADD CONSTRAINT employee_documents_purged CHECK (content_purged_at IS NULL OR status IN ('rejected', 'withdrawn') OR removed_at IS NOT NULL)
            SQL);

        // One current file of each kind per employee, and one per conduct record.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employee_documents_one_current ON employee_documents (employee_id, kind)
                WHERE status = 'approved' AND superseded_at IS NULL AND removed_at IS NULL AND conduct_record_id IS NULL
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employee_documents_one_current_conduct ON employee_documents (conduct_record_id)
                WHERE status = 'approved' AND superseded_at IS NULL AND removed_at IS NULL AND conduct_record_id IS NOT NULL
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION employee_documents_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'employee_documents: an upload is never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                -- What was uploaded never changes.
                IF NEW.id <> OLD.id OR NEW.employee_id <> OLD.employee_id OR NEW.kind <> OLD.kind
                   OR NEW.conduct_record_id IS DISTINCT FROM OLD.conduct_record_id OR NEW.disk <> OLD.disk OR NEW.path <> OLD.path
                   OR NEW.original_name <> OLD.original_name OR NEW.mime <> OLD.mime OR NEW.size_bytes <> OLD.size_bytes
                   OR NEW.sha256 <> OLD.sha256 OR NEW.note IS DISTINCT FROM OLD.note OR NEW.uploaded_by <> OLD.uploaded_by
                   OR NEW.uploaded_at <> OLD.uploaded_at THEN
                    RAISE EXCEPTION 'employee_documents: an upload is never changed' USING ERRCODE = 'restrict_violation';
                END IF;
                -- Decided once; superseded, removed and purged once.
                IF OLD.status <> 'pending' AND (NEW.status <> OLD.status OR NEW.reviewed_by IS DISTINCT FROM OLD.reviewed_by
                   OR NEW.reviewed_at IS DISTINCT FROM OLD.reviewed_at OR NEW.review_note IS DISTINCT FROM OLD.review_note
                   OR NEW.self_approved <> OLD.self_approved) THEN
                    RAISE EXCEPTION 'employee_documents: a decided upload is not decided again' USING ERRCODE = 'restrict_violation';
                END IF;
                IF (OLD.superseded_at IS NOT NULL AND NEW.superseded_at IS DISTINCT FROM OLD.superseded_at)
                   OR (OLD.removed_at IS NOT NULL AND NEW.removed_at IS DISTINCT FROM OLD.removed_at)
                   OR (OLD.content_purged_at IS NOT NULL AND NEW.content_purged_at IS DISTINCT FROM OLD.content_purged_at) THEN
                    RAISE EXCEPTION 'employee_documents: what happened to an upload stays' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER employee_documents_guard BEFORE UPDATE OR DELETE ON employee_documents
                FOR EACH ROW EXECUTE FUNCTION employee_documents_guard();
            CREATE TRIGGER employee_documents_no_truncate BEFORE TRUNCATE ON employee_documents
                FOR EACH STATEMENT EXECUTE FUNCTION records_refuse_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        DB::unprepared('DROP FUNCTION IF EXISTS employee_documents_guard()');
    }
};
