<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4.1 (the design, owner 25 Nov):
 * - «طريقة السداد»: a 28th managed list, for the debt payments (D-045), the same shape as the others (D-024).
 * - «ساعات العمل اليومية» computed from the start and the end of the day, never typed (the design marks it
 *   automatic; two sources would drift).
 * - The absence rate that flags a month (D-042): a branch setting, the workbook's 20% by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150);
            $table->string('search_key', 150)->unique();
            $table->string('code', 50)->nullable()->unique();
            $table->integer('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('archived_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('sort_order');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_methods
                ADD CONSTRAINT payment_methods_label_not_blank CHECK (btrim(label) <> ''),
                ADD CONSTRAINT payment_methods_code_format CHECK (code IS NULL OR code ~ '^[a-z_]+$'),
                ADD CONSTRAINT payment_methods_system_items_stay CHECK (code IS NULL OR archived_at IS NULL),
                ADD CONSTRAINT payment_methods_archived_by CHECK (archived_at IS NOT NULL OR archived_by IS NULL),
                ADD CONSTRAINT payment_methods_sort_order CHECK (sort_order >= 0)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE work_settings
                DROP COLUMN daily_hours,
                ADD COLUMN daily_hours numeric(4, 2) GENERATED ALWAYS AS (round(extract(epoch FROM (ends_at - starts_at)) / 3600, 2)) STORED,
                ADD COLUMN high_absence_rate numeric(5, 2) NOT NULL DEFAULT 20,
                ADD CONSTRAINT work_settings_high_absence_rate CHECK (high_absence_rate > 0 AND high_absence_rate <= 100)
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE work_settings
                DROP COLUMN high_absence_rate,
                DROP COLUMN daily_hours,
                ADD COLUMN daily_hours numeric(4, 2) NOT NULL DEFAULT 8 CHECK (daily_hours > 0 AND daily_hours <= 24)
            SQL);
        Schema::dropIfExists('payment_methods');
    }
};
