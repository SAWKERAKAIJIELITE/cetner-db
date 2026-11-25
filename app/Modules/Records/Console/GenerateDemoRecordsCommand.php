<?php

namespace App\Modules\Records\Console;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development only: years of records for every active demo file, with the change history they would have, in
 * bulk SQL, to measure the queries at a realistic size (D-031). Refused in production. Run after
 * `employees:generate-demo`.
 *
 * Per file and year: twelve months of attendance (sent as a unit's month, 500 files a submission), six movements,
 * one conduct record; a debt for three files in ten.
 */
class GenerateDemoRecordsCommand extends Command
{
    protected $signature = 'records:generate-demo {--years=3 : Years of records}';

    protected $description = 'Development only: generate demo records with their history, to measure performance';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused in production.');

            return self::FAILURE;
        }

        $years = max(1, (int) $this->option('years'));
        $author = StaffUser::query()->where('is_active', true)->orderBy('created_at')->value('id');

        if ($author === null || ! DB::table('employees')->where('file_status', 'active')->exists()) {
            $this->error('Generate the demo files first (php artisan employees:generate-demo).');

            return self::FAILURE;
        }

        $started = microtime(true);
        $months = $years * 12;
        $types = DB::table('movement_types')->whereNull('archived_at')->orderBy('id')->pluck('id')->all();
        $conduct = DB::table('conduct_types')->whereNull('archived_at')->orderBy('id')->pluck('id')->all();
        $arr = fn (array $v) => 'ARRAY['.implode(',', $v).']::bigint[]';
        $reward = (int) DB::table('movement_types')->where('code', 'reward')->value('id');

        DB::transaction(function () use ($author, $months, $types, $conduct, $arr, $reward) {
            // Attendance: one submission per month and 500 files.
            DB::statement(<<<SQL
                CREATE TEMP TABLE demo_attendance ON COMMIT DROP AS
                SELECT gen_random_uuid() AS id, e.id AS employee_id, extract(year FROM d)::int AS year, extract(month FROM d)::int AS month,
                    (row_number() OVER (PARTITION BY d ORDER BY e.id) - 1) / 500 AS chunk,
                    row_number() OVER (PARTITION BY d, (e.rn - 1) / 500 ORDER BY e.id) AS position,
                    22 AS planned, 22 - (abs(hashtext(e.id::text || d::text)) % 6) AS attended, d AS at
                FROM (SELECT id, row_number() OVER (ORDER BY id) AS rn FROM employees WHERE file_status = 'active') e
                CROSS JOIN generate_series(date_trunc('month', now()) - interval '{$months} months', date_trunc('month', now()) - interval '1 month', interval '1 month') d
                SQL);

            DB::statement(<<<'SQL'
                CREATE TEMP TABLE demo_sets ON COMMIT DROP AS
                SELECT gen_random_uuid() AS id, year, month, chunk, count(*) AS n, min(at) AS at FROM demo_attendance GROUP BY year, month, chunk
                SQL);

            DB::statement(<<<SQL
                INSERT INTO record_change_sets (id, record_type, status, items_count, submitted_by, submitted_at, self_approved, reviewed_by, reviewed_at, created_at, updated_at)
                SELECT id, 'attendance', 'approved', n, '{$author}', at, true, '{$author}', at, at, at FROM demo_sets
                SQL);

            DB::statement(<<<'SQL'
                INSERT INTO record_change_items (change_set_id, position, action, record_id, employee_id, new_values)
                SELECT s.id, a.position, 'create', a.id, a.employee_id,
                    jsonb_build_object('year', a.year, 'month', a.month, 'planned_days', a.planned, 'attended_days', a.attended)
                FROM demo_attendance a JOIN demo_sets s USING (year, month, chunk)
                SQL);

            DB::statement(<<<SQL
                INSERT INTO employee_attendance (id, employee_id, year, month, planned_days, attended_days, created_by, approved_by, created_at, updated_at)
                SELECT id, employee_id, year, month, planned, attended, '{$author}', '{$author}', at, at FROM demo_attendance
                SQL);

            // Movements (six a year) and conduct (one a year): one submission each.
            $per = $months / 2;
            DB::statement(<<<SQL
                CREATE TEMP TABLE demo_movements ON COMMIT DROP AS
                SELECT gen_random_uuid() AS id, gen_random_uuid() AS set_id, e.id AS employee_id,
                    (now()::date - (k * 61 + abs(hashtext(e.id::text)) % 60)) AS on_date,
                    ({$arr($types)})[1 + (abs(hashtext(e.id::text || k::text)) % {$this->len($types)})] AS type_id
                FROM employees e CROSS JOIN generate_series(1, {$per}) k WHERE e.file_status = 'active'
                SQL);

            DB::statement(<<<SQL
                INSERT INTO record_change_sets (id, record_type, status, items_count, submitted_by, submitted_at, self_approved, reviewed_by, reviewed_at, created_at, updated_at)
                SELECT set_id, 'movements', 'approved', 1, '{$author}', on_date, true, '{$author}', on_date, on_date, on_date FROM demo_movements
                SQL);

            DB::statement(<<<SQL
                INSERT INTO record_change_items (change_set_id, position, action, record_id, employee_id, new_values)
                SELECT set_id, 1, 'create', id, employee_id, jsonb_build_object('occurred_on', on_date, 'movement_type_id', type_id, 'description', 'حركة تجريبية',
                    'amount', CASE WHEN type_id = {$reward} THEN '10000.00' END) FROM demo_movements
                SQL);

            DB::statement(<<<SQL
                INSERT INTO employee_movements (id, employee_id, occurred_on, movement_type_id, description, amount, days, created_by, approved_by, created_at, updated_at)
                SELECT id, employee_id, on_date, type_id, 'حركة تجريبية', CASE WHEN type_id = {$reward} THEN 10000 END, 3,
                    '{$author}', '{$author}', on_date, on_date FROM demo_movements
                SQL);

            $yearsCount = intdiv($months, 12);
            DB::statement(<<<SQL
                INSERT INTO employee_conduct_records (id, employee_id, occurred_on, conduct_type_id, subject, created_by, approved_by, created_at, updated_at)
                SELECT gen_random_uuid(), e.id, now()::date - (k * 365 - abs(hashtext(e.id::text)) % 300),
                    ({$arr($conduct)})[1 + (abs(hashtext(e.id::text || k::text)) % {$this->len($conduct)})], 'سجل تجريبي',
                    '{$author}', '{$author}', now(), now()
                FROM employees e CROSS JOIN generate_series(1, {$yearsCount}) k WHERE e.file_status = 'active'
                SQL);

            DB::statement(<<<SQL
                INSERT INTO employee_debts (id, employee_id, creditor, amount, paid_amount, monthly_installment, due_on, created_by, approved_by, created_at, updated_at)
                SELECT gen_random_uuid(), id, 'الجمعية', 500000, (abs(hashtext(id::text)) % 6) * 100000, 50000, now()::date + (abs(hashtext(id::text)) % 400 - 100),
                    '{$author}', '{$author}', now(), now()
                FROM employees WHERE file_status = 'active' AND abs(hashtext(id::text)) % 10 < 3
                SQL);

            // A queue: a pending month of corrections.
            DB::statement(<<<SQL
                INSERT INTO record_change_sets (id, record_type, status, items_count, submitted_by, submitted_at, created_at, updated_at)
                SELECT gen_random_uuid(), 'movements', 'pending', 1, '{$author}', now(), now(), now() FROM generate_series(1, 200)
                SQL);
        });

        DB::statement('ANALYZE');

        $this->table(['table', 'rows'], collect(['employee_attendance', 'employee_movements', 'employee_conduct_records', 'employee_debts', 'record_change_sets', 'record_change_items'])
            ->map(fn ($t) => [$t, number_format(DB::table($t)->count())])->all());
        $this->info(sprintf('Done in %.1f s.', microtime(true) - $started));

        return self::SUCCESS;
    }

    private function len(array $values): int
    {
        return max(1, count($values));
    }
}
