<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The weekly days off (D-043), so the attendance can suggest a month's planned days: ISO weekdays (1 Monday …
 * 7 Sunday), as many as the week has days without work. Friday and Saturday by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION jsonb_elements_distinct(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
                SELECT count(DISTINCT e) = count(*) FROM jsonb_array_elements(value) AS e
            $$;
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE work_settings
                ADD COLUMN weekly_days_off jsonb NOT NULL DEFAULT '[5, 6]',
                ADD CONSTRAINT work_settings_days_off CHECK (
                    jsonb_typeof(weekly_days_off) = 'array'
                    AND jsonb_array_length(weekly_days_off) = 7 - work_days_per_week
                    AND weekly_days_off <@ '[1, 2, 3, 4, 5, 6, 7]'::jsonb
                    AND jsonb_elements_distinct(weekly_days_off)
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_settings DROP CONSTRAINT work_settings_days_off, DROP COLUMN weekly_days_off');
        DB::unprepared('DROP FUNCTION IF EXISTS jsonb_elements_distinct(jsonb)');
    }
};
