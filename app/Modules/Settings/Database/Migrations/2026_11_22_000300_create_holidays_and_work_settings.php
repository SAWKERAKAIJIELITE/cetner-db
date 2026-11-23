<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** «العطل الرسمية» and «إعدادات الفرع» (D-027, D-028). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('starts_on');
            $table->date('ends_on');
            // The workbook's «أيام»: both ends included.
            $table->integer('days')->storedAs('ends_on - starts_on + 1');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('starts_on');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE holidays
                ADD CONSTRAINT holidays_name_not_blank CHECK (btrim(name) <> ''),
                ADD CONSTRAINT holidays_range CHECK (ends_on >= starts_on AND ends_on - starts_on < 366)
            SQL);

        // One row, always present: the branch's working rules and the four review windows.
        Schema::create('work_settings', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('branch_name', 150);
            $table->decimal('daily_hours', 4, 2);
            $table->time('starts_at');
            $table->time('ends_at');
            $table->smallInteger('late_after_minutes');
            $table->smallInteger('work_days_per_week');
            $table->string('currency', 20);
            $table->smallInteger('annual_leave_days');
            $table->smallInteger('review_window_monthly');
            $table->smallInteger('review_window_quarterly');
            $table->smallInteger('review_window_semi_annual');
            $table->smallInteger('review_window_annual');
            $table->foreignUuid('updated_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_settings
                ADD CONSTRAINT work_settings_single_row CHECK (id = 1),
                ADD CONSTRAINT work_settings_branch_not_blank CHECK (btrim(branch_name) <> ''),
                ADD CONSTRAINT work_settings_currency_not_blank CHECK (btrim(currency) <> ''),
                ADD CONSTRAINT work_settings_daily_hours CHECK (daily_hours > 0 AND daily_hours <= 24),
                ADD CONSTRAINT work_settings_hours_order CHECK (ends_at > starts_at),
                ADD CONSTRAINT work_settings_late CHECK (late_after_minutes BETWEEN 0 AND 240),
                ADD CONSTRAINT work_settings_days CHECK (work_days_per_week BETWEEN 1 AND 7),
                ADD CONSTRAINT work_settings_leave CHECK (annual_leave_days BETWEEN 0 AND 365),
                ADD CONSTRAINT work_settings_review_windows CHECK (
                    review_window_monthly > 0
                    AND review_window_monthly <= review_window_quarterly
                    AND review_window_quarterly <= review_window_semi_annual
                    AND review_window_semi_annual <= review_window_annual
                    AND review_window_annual <= 1000
                )
            SQL);

        // The workbook's values.
        DB::table('work_settings')->insert([
            'id' => 1,
            'branch_name' => 'القوى البشرية',
            'daily_hours' => 8,
            'starts_at' => '08:00',
            'ends_at' => '16:00',
            'late_after_minutes' => 10,
            'work_days_per_week' => 5,
            'currency' => 'ل.س',
            'annual_leave_days' => 21,
            'review_window_monthly' => 35,
            'review_window_quarterly' => 100,
            'review_window_semi_annual' => 190,
            'review_window_annual' => 370,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_settings');
        Schema::dropIfExists('holidays');
    }
};
