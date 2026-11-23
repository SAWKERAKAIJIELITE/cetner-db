<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The managed lists of «الإعدادات»: one table per list, all the same shape (D-024), so that every field
 * of the employee's file is a plain foreign key to its own list. Integer keys (D-003's exception);
 * items are archived, never deleted (D-007).
 *
 * The names are written out here, not read from ListCatalogue: a migration must keep doing what it did.
 */
return new class extends Migration
{
    public const TABLES = [
        'genders', 'blood_types', 'marital_statuses', 'education_levels', 'current_studies', 'study_years',
        'position_levels', 'job_titles', 'employee_statuses', 'job_statuses', 'org_stages', 'org_stage_levels',
        'contract_types', 'work_schedules', 'health_statuses', 'ability_levels', 'housing_types',
        'vehicle_ownerships', 'guardian_relations', 'external_entity_types', 'external_link_types',
        'external_schedules', 'movement_types', 'conduct_types', 'conduct_statuses', 'debt_types', 'work_natures',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('label', 150);
                // ArabicText::searchKey(label): the same word typed differently is one item.
                $table->string('search_key', 150)->unique();
                // Set by the catalogue only: the code's logic refers to this item (D-025).
                $table->string('code', 50)->nullable()->unique();
                $table->integer('sort_order')->default(0);
                $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
                $table->timestampTz('archived_at')->nullable();
                $table->foreignUuid('archived_by')->nullable()->constrained('staff_users')->restrictOnDelete();
                $table->timestampsTz();

                $table->index('sort_order');
            });

            DB::statement(<<<SQL
                ALTER TABLE {$name}
                    ADD CONSTRAINT {$name}_label_not_blank CHECK (btrim(label) <> ''),
                    ADD CONSTRAINT {$name}_code_format CHECK (code IS NULL OR code ~ '^[a-z_]+$'),
                    ADD CONSTRAINT {$name}_system_items_stay CHECK (code IS NULL OR archived_at IS NULL),
                    ADD CONSTRAINT {$name}_archived_by CHECK (archived_at IS NOT NULL OR archived_by IS NULL),
                    ADD CONSTRAINT {$name}_sort_order CHECK (sort_order >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
