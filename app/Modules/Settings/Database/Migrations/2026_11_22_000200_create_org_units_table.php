<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The organisational structure (جهات العمل): a tree of at most six levels (D-026).
 * The workbook repeats the six names on every row and on every employee; here each unit is one row and
 * an employee will point at the deepest unit (Step 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->restrictOnDelete();
            $table->smallInteger('level');
            $table->string('name', 150);
            $table->string('search_key', 150);
            $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('parent_id');
        });

        // Siblings have distinct names (top-level units among themselves).
        DB::statement('CREATE UNIQUE INDEX org_units_unique_sibling_name ON org_units (COALESCE(parent_id, 0), search_key)');

        DB::statement(<<<'SQL'
            ALTER TABLE org_units
                ADD CONSTRAINT org_units_level_range CHECK (level BETWEEN 1 AND 6),
                ADD CONSTRAINT org_units_root_is_level_one CHECK ((level = 1) = (parent_id IS NULL)),
                ADD CONSTRAINT org_units_name_not_blank CHECK (btrim(name) <> ''),
                ADD CONSTRAINT org_units_archived_by CHECK (archived_at IS NOT NULL OR archived_by IS NULL)
            SQL);

        // A child is one level below its parent; an active unit has an active parent; a unit with active
        // children is not archived. The Actions say why in Arabic; this is the last guard.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION org_units_check_tree() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                parent org_units%ROWTYPE;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT * INTO parent FROM org_units WHERE id = NEW.parent_id;
                    IF NEW.level <> parent.level + 1 THEN
                        RAISE EXCEPTION 'org_units: level must be the parent''s plus one' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.archived_at IS NULL AND parent.archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'org_units: an active unit needs an active parent' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF TG_OP = 'UPDATE' AND NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL
                   AND EXISTS (SELECT 1 FROM org_units WHERE parent_id = NEW.id AND archived_at IS NULL) THEN
                    RAISE EXCEPTION 'org_units: archive the active children first' USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND (NEW.parent_id IS DISTINCT FROM OLD.parent_id OR NEW.level <> OLD.level) THEN
                    RAISE EXCEPTION 'org_units: a unit is not moved' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER org_units_tree
                BEFORE INSERT OR UPDATE ON org_units
                FOR EACH ROW EXECUTE FUNCTION org_units_check_tree();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
        DB::unprepared('DROP FUNCTION IF EXISTS org_units_check_tree()');
    }
};
