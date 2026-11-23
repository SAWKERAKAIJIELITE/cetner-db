<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The managed lists of «الإعدادات» (D-024): the kinds are fixed by the code, their items are data.
 * Integer keys (D-003's exception); items are archived, never deleted (D-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_lists', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label_ar', 150);
            // Which fields of the file use it, shown to whoever edits it.
            $table->string('description_ar', 500)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE lookup_lists ADD CONSTRAINT lookup_lists_key_format CHECK (key ~ '^[a-z_]+$')");

        Schema::create('lookup_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookup_list_id')->constrained('lookup_lists')->restrictOnDelete();
            $table->string('label', 150);
            // ArabicText::searchKey(label): the same word typed differently is one item.
            $table->string('search_key', 150);
            // Set by the catalogue only: the code's logic refers to this item (D-025).
            $table->string('code', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['lookup_list_id', 'search_key']);
            $table->index(['lookup_list_id', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX lookup_items_one_code_per_list ON lookup_items (lookup_list_id, code) WHERE code IS NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE lookup_items
                ADD CONSTRAINT lookup_items_label_not_blank CHECK (btrim(label) <> ''),
                ADD CONSTRAINT lookup_items_code_format CHECK (code IS NULL OR code ~ '^[a-z_]+$'),
                ADD CONSTRAINT lookup_items_system_items_stay CHECK (code IS NULL OR archived_at IS NULL),
                ADD CONSTRAINT lookup_items_archived_by CHECK (archived_at IS NOT NULL OR archived_by IS NULL),
                ADD CONSTRAINT lookup_items_sort_order CHECK (sort_order >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_items');
        Schema::dropIfExists('lookup_lists');
    }
};
