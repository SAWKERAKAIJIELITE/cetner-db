<?php

namespace App\Modules\Reports\Support;

use App\Modules\Employees\Enums\Cycle;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * «داش بورد الموظفين» (D-051): the workbook's tiles over the approved files, within its six filters (and the unit).
 * A tile the workbook gives one list item («متعاقد دوام», «متزوج», «دكتوراه» …) becomes one tile per item of that
 * list, found by id, so renamed and added items are counted too. One aggregate query and one grouped query per
 * list.
 */
final class EmployeesDashboard
{
    /** The lists the workbook counts by item, with the group each goes to. */
    private const BREAKDOWNS = [
        'job_status_id' => 'job_status',
        'marital_status_id' => 'marital_status',
        'org_stage_id' => 'org_stage',
        'health_status_id' => 'health_status',
        'housing_type_id' => 'housing_type',
        'education_level_id' => 'education_level',
        'work_schedule_id' => 'work_schedule',
        'work_nature_id' => 'work_nature',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total: int, groups: list<array{key: string, title: string, tiles: list<array<string, mixed>>}>}
     */
    public static function build(array $filters): array
    {
        $today = BusinessDay::today();
        $in30 = $today->addDays(30)->format('Y-m-d');
        $todayYmd = $today->format('Y-m-d');
        $before = EmployeeScope::overdueBefore();

        $active = ListItems::id('employee_status', 'active');
        $inactive = ListItems::id('employee_status', 'inactive');
        $male = ListItems::id('gender', 'male');
        $female = ListItems::id('gender', 'female');
        $notStudying = ListItems::id('current_study', 'none');

        $overdue = [];
        $current = [];
        $bindings = [];

        foreach (Cycle::reviewed() as $cycle) {
            $overdue[] = "count(*) FILTER (WHERE r.{$cycle->value} IS NULL OR r.{$cycle->value} < ?) AS overdue_{$cycle->value}";
            $current[] = "r.{$cycle->value} >= ?";
            $bindings[] = $before[$cycle->value];
        }

        $sql = implode(",\n", [
            'count(*) AS total',
            'count(*) FILTER (WHERE e.employee_status_id = ?) AS active',
            'count(*) FILTER (WHERE e.employee_status_id = ?) AS inactive',
            'count(*) FILTER (WHERE e.gender_id = ?) AS male',
            'count(*) FILTER (WHERE e.gender_id = ?) AS female',
            "avg(date_part('year', age(?::date, e.birth_date))) AS average_age",
            'count(*) FILTER (WHERE e.job_status_id IS NULL) AS job_status_unset',
            'count(*) FILTER (WHERE e.contract_ends_on >= ?::date) AS contracts_valid',
            'count(*) FILTER (WHERE e.contract_ends_on BETWEEN ?::date AND ?::date) AS contracts_ending',
            'count(*) FILTER (WHERE e.contract_ends_on < ?::date) AS contracts_expired',
            'count(*) FILTER (WHERE e.children_count > 0) AS with_children',
            'avg(round(((coalesce(e.discharge_date, ?::date) - least(e.assignment_date, e.appointment_date, e.contract_signed_on))::numeric / 365.25), 1)) AS average_service',
            'coalesce(sum(e.base_salary), 0) AS salaries_total',
            'avg(e.base_salary) AS salary_average',
            'max(e.base_salary) AS salary_max',
            'min(e.base_salary) AS salary_min',
            'count(*) FILTER (WHERE e.salary_eligible) AS salary_eligible',
            'count(*) FILTER (WHERE e.has_weapon) AS has_weapon',
            'count(*) FILTER (WHERE e.quran_licensed) AS quran_licensed',
            'count(*) FILTER (WHERE e.preaches_now) AS preaches_now',
            'count(*) FILTER (WHERE e.teaches_now) AS teaches_now',
            'count(*) FILTER (WHERE e.owns_car) AS owns_car',
            'count(*) FILTER (WHERE e.current_study_id IS NOT NULL AND e.current_study_id IS DISTINCT FROM ?) AS studying',
            'count(*) FILTER (WHERE e.has_external_link) AS has_external_link',
            'count(*) FILTER (WHERE e.quran_parts = 30) AS quran_hafiz',
            'count(*) FILTER (WHERE d.remaining > 0) AS with_debts',
            'coalesce(sum(d.remaining), 0) AS debts_remaining',
            'count(*) FILTER (WHERE e.did_umrah) AS did_umrah',
            'count(*) FILTER (WHERE e.did_hajj) AS did_hajj',
            'count(*) FILTER (WHERE e.second_study_level_id IS NOT NULL) AS second_study',
            "count(*) FILTER (WHERE btrim(coalesce(e.military_rank, '')) <> '') AS military_rank",
            'avg(e.completeness) AS average_completeness',
            'count(*) FILTER (WHERE e.completeness >= 99.99) AS complete',
            'count(*) FILTER (WHERE e.completeness >= 80) AS complete_80',
            'count(*) FILTER (WHERE e.completeness < 50) AS below_50',
            'count(*) FILTER (WHERE doc.attached >= 13) AS documents_complete',
            'count(*) FILTER (WHERE doc.has_portrait IS NOT TRUE) AS without_portrait',
            ...$overdue,
            'count(*) FILTER (WHERE '.implode(' AND ', $current).') AS up_to_date',
        ]);

        $row = DB::query()->fromSub(EmployeeScope::files($filters), 'e')
            ->leftJoinSub(EmployeeScope::debts(), 'd', 'd.employee_id', '=', 'e.id')
            ->leftJoinSub(EmployeeScope::documents(), 'doc', 'doc.employee_id', '=', 'e.id')
            ->leftJoinSub(EmployeeScope::reviews(), 'r', 'r.employee_id', '=', 'e.id')
            ->selectRaw($sql, [
                $active, $inactive, $male, $female, $todayYmd, $todayYmd, $todayYmd, $in30, $todayYmd, $todayYmd,
                $notStudying, ...$bindings, ...$bindings,
            ])
            ->first();

        $counts = self::breakdowns($filters);
        $total = (int) $row->total;
        $n = fn (string $k) => (int) $row->{$k};
        $item = fn (string $list, ?int $id) => ListItems::ref($list, $id);

        $cycleTiles = [];

        foreach (Cycle::reviewed() as $cycle) {
            $cycleTiles[] = Tile::make('overdue.'.$cycle->value, 'متأخر — '.$cycle->label(), $n('overdue_'.$cycle->value));
        }

        $cycleTiles[] = Tile::make('up_to_date', 'مُحدَّث في كل الدورات', $n('up_to_date'));
        $cycleTiles[] = Tile::make('monthly_compliance', 'الالتزام بالتحديث الشهري', Tile::percent($total - $n('overdue_monthly'), $total), Tile::PERCENT);

        return [
            'total' => $total,
            'groups' => [
                Tile::group('general', 'الإحصائيات العامة', [
                    Tile::make('total', 'إجمالي', $total),
                    Tile::make('active', 'فعالون', $n('active'), Tile::COUNT, $item('employee_status', $active)),
                    Tile::make('inactive', 'غير فعالين', $n('inactive'), Tile::COUNT, $item('employee_status', $inactive)),
                    Tile::make('male', 'ذكور', $n('male'), Tile::COUNT, $item('gender', $male)),
                    Tile::make('female', 'إناث', $n('female'), Tile::COUNT, $item('gender', $female)),
                    Tile::make('average_age', 'متوسط العمر', self::round($row->average_age, 1), Tile::YEARS),
                ]),
                Tile::group('job_status', 'الحالة الوظيفية', [
                    ...Tile::items('job_status', 'job_status', $counts['job_status_id']),
                    Tile::make('job_status_unset', 'حالة وظيفية غير محددة', $n('job_status_unset')),
                ]),
                Tile::group('contracts', 'العقود', [
                    Tile::make('contracts_valid', 'عقود سارية', $n('contracts_valid')),
                    Tile::make('contracts_ending', 'تنتهي خلال 30 يوماً', $n('contracts_ending')),
                    Tile::make('contracts_expired', 'عقود منتهية', $n('contracts_expired')),
                    Tile::make('active_share', 'نسبة الفعالين', Tile::percent($n('active'), $total), Tile::PERCENT),
                ]),
                Tile::group('personal', 'البيانات الشخصية', [
                    ...Tile::items('marital_status', 'marital_status', $counts['marital_status_id']),
                    Tile::make('with_children', 'لديهم أولاد', $n('with_children')),
                    Tile::make('average_service', 'متوسط الخدمة (سنة)', self::round($row->average_service, 1), Tile::YEARS),
                    Tile::make('salaries_total', 'إجمالي الرواتب', Tile::money($row->salaries_total), Tile::MONEY),
                ]),
                Tile::group('military', 'العسكرية والدعوية', [
                    ...Tile::items('org_stage', 'org_stage', $counts['org_stage_id']),
                    Tile::make('has_weapon', 'يمتلك سلاح', $n('has_weapon')),
                    Tile::make('quran_licensed', 'مجاز بالقرآن', $n('quran_licensed')),
                    Tile::make('preaches_now', 'يخطب حالياً', $n('preaches_now')),
                    Tile::make('teaches_now', 'يدرّس حالياً', $n('teaches_now')),
                ]),
                Tile::group('health_housing', 'الصحة والسكن', [
                    ...Tile::items('health_status', 'health_status', $counts['health_status_id']),
                    ...Tile::items('housing_type', 'housing_type', $counts['housing_type_id']),
                    Tile::make('owns_car', 'سيارة', $n('owns_car')),
                ]),
                Tile::group('education', 'التحصيل العلمي', [
                    ...Tile::items('education_level', 'education_level', $counts['education_level_id']),
                    Tile::make('studying', 'طالب حالياً', $n('studying')),
                ]),
                Tile::group('schedule', 'الدوام والارتباط والقرآن', [
                    ...Tile::items('work_schedule', 'work_schedule', $counts['work_schedule_id']),
                    Tile::make('has_external_link', 'لديه ارتباط خارجي', $n('has_external_link')),
                    Tile::make('quran_hafiz', 'حافظ للقرآن كاملاً', $n('quran_hafiz')),
                ]),
                Tile::group('finance', 'المالية والديون', [
                    Tile::make('salary_average', 'متوسط الراتب', Tile::money($row->salary_average), Tile::MONEY),
                    Tile::make('salary_max', 'أعلى راتب', Tile::money($row->salary_max), Tile::MONEY),
                    Tile::make('salary_min', 'أقل راتب', Tile::money($row->salary_min), Tile::MONEY),
                    Tile::make('salary_eligible', 'مستحقو الراتب', $n('salary_eligible')),
                    Tile::make('with_debts', 'عليهم ديون', $n('with_debts')),
                    Tile::make('debts_remaining', 'إجمالي الديون المتبقية', Tile::money($row->debts_remaining), Tile::MONEY),
                ]),
                Tile::group('nature', 'طبيعة العمل والعبادات والمؤهلات', [
                    ...Tile::items('work_nature', 'work_nature', $counts['work_nature_id']),
                    Tile::make('did_umrah', 'أدّوا العمرة', $n('did_umrah')),
                    Tile::make('did_hajj', 'أدّوا فريضة الحج', $n('did_hajj')),
                    Tile::make('second_study', 'لديهم دراسة ثانية', $n('second_study')),
                    Tile::make('military_rank', 'يحملون رتبة عسكرية', $n('military_rank')),
                ]),
                Tile::group('quality', 'جودة البيانات', [
                    Tile::make('average_completeness', 'متوسط نسبة الاكتمال', self::round($row->average_completeness, 2), Tile::PERCENT),
                    Tile::make('complete', 'سجلات مكتملة 100%', $n('complete')),
                    Tile::make('complete_80', 'اكتمال 80% فأكثر', $n('complete_80')),
                    Tile::make('below_50', 'اكتمال أقل من 50%', $n('below_50')),
                    Tile::make('documents_complete', 'الوثائق مكتملة (13/13)', $n('documents_complete')),
                    Tile::make('without_portrait', 'بلا صورة شخصية', $n('without_portrait')),
                ]),
                Tile::group('cycles', 'متابعة دورات التحديث', $cycleTiles),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, array<int, int>> column => item id => count
     */
    private static function breakdowns(array $filters): array
    {
        $out = [];

        foreach (array_keys(self::BREAKDOWNS) as $column) {
            $out[$column] = DB::query()->fromSub(EmployeeScope::files($filters), 'e')
                ->whereNotNull("e.{$column}")->groupBy("e.{$column}")
                ->selectRaw("e.{$column} AS id, count(*) AS n")->pluck('n', 'id')
                ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])->all();
        }

        return $out;
    }

    private static function round(mixed $value, int $decimals): ?float
    {
        return $value === null ? null : round((float) $value, $decimals);
    }
}
