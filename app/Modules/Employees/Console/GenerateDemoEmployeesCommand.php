<?php

namespace App\Modules\Employees\Console;

use App\Modules\Employees\Actions\UpdateFieldDefinition;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development only: fills the database with demo files and years of history in bulk SQL, to measure the
 * queries at a realistic size before production (D-031). Refused in production.
 */
class GenerateDemoEmployeesCommand extends Command
{
    protected $signature = 'employees:generate-demo {count=4000} {--years=3 : Years of monthly reviews and changes}';

    protected $description = 'Development only: generate demo employees with history, to measure performance';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused in production.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->argument('count'));
        $months = max(1, (int) $this->option('years')) * 12;
        $author = StaffUser::query()->where('is_active', true)->orderBy('created_at')->value('id');

        if ($author === null) {
            $this->error('Seed the demo accounts first (php artisan db:seed).');

            return self::FAILURE;
        }

        $started = microtime(true);
        $ids = fn (string $table) => DB::table($table)->whereNull('archived_at')->pluck('id')->all() ?: [null];
        $units = DB::table('org_units')->whereNull('archived_at')->pluck('id')->all() ?: [null];
        $pick = fn (array $values) => 'ARRAY['.implode(',', array_map(fn ($v) => $v ?? 'NULL', $values)).']::bigint[]';

        DB::transaction(function () use ($count, $months, $author, $ids, $units, $pick) {
            $first = ['أحمد', 'محمد', 'خالد', 'سامر', 'يوسف', 'عمر', 'علي', 'حسن', 'مصطفى', 'إبراهيم', 'فاطمة', 'مريم', 'ليلى', 'هدى'];
            $family = ['الخطيب', 'العلي', 'الأحمد', 'الحسن', 'السيد', 'النجار', 'الحلبي', 'الشامي', 'المصري', 'الزعبي'];
            $arr = fn (array $v) => "ARRAY['".implode("','", $v)."']";

            DB::statement(<<<SQL
                INSERT INTO employees (id, file_status, first_name, father_name, family_name, national_number, personal_number,
                    gender_id, birth_date, org_unit_id, employee_status_id, job_status_id, contract_starts_on, contract_ends_on,
                    base_salary, residence_city, phone, marital_status_id, children_count, height_cm, created_by, activated_at, created_at, updated_at)
                SELECT gen_random_uuid(), 'active',
                    ({$arr($first)})[1 + (n % 14)], ({$arr($first)})[1 + ((n / 14) % 14)], ({$arr($family)})[1 + ((n / 7) % 10)],
                    lpad((50000000000 + n)::text, 11, '0'), (100000 + n)::text,
                    ({$pick($ids('genders'))})[1 + (n % 2)], DATE '1970-01-01' + (n % 12000),
                    ({$pick($units)})[1 + (n % {$this->len($units)})],
                    ({$pick($ids('employee_statuses'))})[1 + (CASE WHEN n % 10 = 0 THEN 1 ELSE 0 END)],
                    ({$pick($ids('job_statuses'))})[1 + (n % {$this->len($ids('job_statuses'))})],
                    DATE '2025-01-01' + (n % 365), DATE '2026-01-01' + (n % 700),
                    300000 + (n % 50) * 10000, 'دمشق', '0991' || lpad((n % 1000000)::text, 6, '0'),
                    ({$pick($ids('marital_statuses'))})[1 + (n % {$this->len($ids('marital_statuses'))})], n % 6, 160 + (n % 30),
                    '{$author}', now(), now(), now()
                FROM generate_series(1, {$count}) AS n
                SQL);

            // History: the creation, then a monthly confirmation every month with one change, a quarterly one
            // every quarter, for every file.
            DB::statement(<<<SQL
                INSERT INTO employee_change_sets (id, employee_id, kind, cycle, period, status, submitted_by, submitted_at,
                    self_approved, reviewed_by, reviewed_at, created_at, updated_at)
                SELECT gen_random_uuid(), e.id, 'review', c.cycle, c.period, 'approved', '{$author}', c.at,
                    true, '{$author}', c.at, c.at, c.at
                FROM employees e
                CROSS JOIN LATERAL (
                    SELECT 'monthly' AS cycle, to_char(d, 'YYYY-MM') AS period, d AS at
                    FROM generate_series(date_trunc('month', now()) - interval '{$months} months', date_trunc('month', now()) - interval '1 month', interval '1 month') d
                    UNION ALL
                    SELECT 'quarterly', to_char(d, 'YYYY') || '-Q' || extract(quarter FROM d), d
                    FROM generate_series(date_trunc('quarter', now()) - interval '{$months} months', date_trunc('quarter', now()) - interval '3 months', interval '3 months') d
                ) c
                WHERE e.file_status = 'active'
                SQL);

            DB::statement(<<<'SQL'
                INSERT INTO employee_change_items (change_set_id, field, old_value, new_value)
                SELECT s.id, 'residence_district', to_jsonb('حي ' || (row_number() OVER (PARTITION BY s.employee_id ORDER BY s.submitted_at) - 1)::text),
                       to_jsonb('حي ' || (row_number() OVER (PARTITION BY s.employee_id ORDER BY s.submitted_at))::text)
                FROM employee_change_sets s WHERE s.cycle = 'monthly'
                SQL);

            DB::statement(<<<'SQL'
                INSERT INTO employee_reviews (employee_id, cycle, period, reviewed_on, change_set_id, confirmed_by, approved_by, created_at)
                SELECT employee_id, cycle, period, submitted_at::date, id, submitted_by, reviewed_by, submitted_at
                FROM employee_change_sets WHERE kind = 'review' AND status = 'approved'
                SQL);

            // A queue of pending changes.
            DB::statement(<<<SQL
                INSERT INTO employee_change_sets (id, employee_id, kind, status, submitted_by, submitted_at, created_at, updated_at)
                SELECT gen_random_uuid(), id, 'update', 'pending', '{$author}', now(), now(), now()
                FROM employees WHERE file_status = 'active' ORDER BY id LIMIT 200
                SQL);
        });

        UpdateFieldDefinition::recomputeAll();
        DB::statement('ANALYZE');

        $this->table(['table', 'rows'], collect(['employees', 'employee_change_sets', 'employee_change_items', 'employee_reviews'])
            ->map(fn ($t) => [$t, number_format(DB::table($t)->count())])->all());
        $this->info(sprintf('Done in %.1f s.', microtime(true) - ($started ?? microtime(true))));

        return self::SUCCESS;
    }

    private function len(array $values): int
    {
        return max(1, count($values));
    }
}
