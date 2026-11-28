<?php

namespace App\Modules\Reports\Support;

use App\Modules\Records\Support\LeaveTypes;
use App\Modules\Settings\Models\WorkSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «داش بورد الحركات» (D-051): the workbook's figures of the movements, conduct and attendance records for a year
 * (or one of its months), over the approved records; with a chosen employee, theirs too. Types are found by their
 * codes and leaves as LeaveTypes says, so renamed items keep their tiles; each leave type has its own tile.
 */
final class MovementsDashboard
{
    /**
     * @return array{period: array{year: int, month: ?int, from: string, to: string}, employee_id: ?string, groups: list<array{key: string, title: string, tiles: list<array<string, mixed>>}>}
     */
    public static function build(ReportPeriod $period, ?string $employeeId = null): array
    {
        $types = self::movementTypes();
        $all = self::movementsByType(null, null);
        $inPeriod = self::movementsByType($period, null);
        $conduct = self::conductByCode($period, null);
        $attendance = self::attendance($period, null);

        $files = DB::table('employees')->where('file_status', 'active')
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE employee_status_id = ?) AS active, coalesce(sum(base_salary), 0) AS salaries',
                [ListItems::id('employee_status', 'active')])->first();

        $code = fn (array $byType, string $c, string $what = 'n') => array_sum(array_map(
            fn ($id) => $byType[$id][$what] ?? 0, $types['codes'][$c] ?? []));
        $leaves = fn (array $byType, string $what = 'n') => array_sum(array_map(fn ($id) => $byType[$id][$what] ?? 0, array_keys($types['leaves'])));

        $groups = [
            Tile::group('overall', 'المؤشرات العامة', [
                Tile::make('employees', 'إجمالي الموظفين', (int) $files->total),
                Tile::make('active', 'الفعالون', (int) $files->active),
                Tile::make('salaries_total', 'إجمالي الرواتب', Tile::money($files->salaries), Tile::MONEY),
                Tile::make('movements', 'إجمالي الحركات', array_sum(array_column($all, 'n'))),
                Tile::make('rewards_total', 'إجمالي المكافآت', Tile::money($code($all, 'reward', 'amount')), Tile::MONEY),
                Tile::make('deductions_total', 'إجمالي الخصومات', Tile::money($code($all, 'deduction', 'amount')), Tile::MONEY),
            ]),
            Tile::group('leaves', 'الإجازات', [
                ...array_map(fn (array $item) => Tile::make('leave.'.$item['id'], $item['name_ar'], $inPeriod[$item['id']]['n'] ?? 0, Tile::COUNT, $item), array_values($types['leaves'])),
                Tile::make('leave_days', 'إجمالي أيام الإجازة', $leaves($inPeriod, 'days'), Tile::DAYS),
                Tile::make('leaves', 'إجمالي الإجازات', $leaves($inPeriod)),
            ]),
            Tile::group('finance', 'المالية', [
                Tile::make('rewards', 'مكافأة (عدد)', $code($inPeriod, 'reward'), Tile::COUNT, $types['refs']['reward'] ?? null),
                Tile::make('rewards_amount', 'مكافأة (مبلغ)', Tile::money($code($inPeriod, 'reward', 'amount')), Tile::MONEY, $types['refs']['reward'] ?? null),
                Tile::make('deductions', 'خصم (عدد)', $code($inPeriod, 'deduction'), Tile::COUNT, $types['refs']['deduction'] ?? null),
                Tile::make('deductions_amount', 'خصم (مبلغ)', Tile::money($code($inPeriod, 'deduction', 'amount')), Tile::MONEY, $types['refs']['deduction'] ?? null),
                Tile::make('courses', $types['refs']['course']['name_ar'] ?? 'دورة تدريبية', $code($inPeriod, 'course'), Tile::COUNT, $types['refs']['course'] ?? null),
                Tile::make('net', 'صافي', Tile::money($code($inPeriod, 'reward', 'amount') - $code($inPeriod, 'deduction', 'amount')), Tile::MONEY),
            ]),
            Tile::group('administrative', 'الحركات الإدارية والتنظيمية', [
                Tile::make('transfers', $types['refs']['transfer']['name_ar'] ?? 'نقل داخلي', $code($inPeriod, 'transfer'), Tile::COUNT, $types['refs']['transfer'] ?? null),
                Tile::make('status_changes', $types['refs']['status_change']['name_ar'] ?? 'تغيير حالة', $code($inPeriod, 'status_change'), Tile::COUNT, $types['refs']['status_change'] ?? null),
                Tile::make('promotions', $types['refs']['promotion']['name_ar'] ?? 'ترقية', $code($inPeriod, 'promotion'), Tile::COUNT, $types['refs']['promotion'] ?? null),
                Tile::make('appointments', 'تكليف / تعيين / تثبيت', $code($inPeriod, 'assignment') + $code($inPeriod, 'appointment') + $code($inPeriod, 'confirmation')),
                Tile::make('resignations', $types['refs']['resignation']['name_ar'] ?? 'استقالة', $code($inPeriod, 'resignation'), Tile::COUNT, $types['refs']['resignation'] ?? null),
                Tile::make('discharges', $types['refs']['discharge']['name_ar'] ?? 'تسريح', $code($inPeriod, 'discharge'), Tile::COUNT, $types['refs']['discharge'] ?? null),
            ]),
            Tile::group('conduct_attendance', 'السلوك الوظيفي والدوام', [
                Tile::make('complaints', 'الشكاوى', $conduct['complaint'] ?? 0),
                Tile::make('warnings', 'الإنذارات', $conduct['warning'] ?? 0),
                Tile::make('punishments', 'العقوبات', $conduct['punishment'] ?? 0),
                Tile::make('thanks', 'شكر وتقدير', $conduct['thanks'] ?? 0),
                Tile::make('absent_days', 'إجمالي أيام الغياب', $attendance['absent'], Tile::DAYS),
                Tile::make('absence_rate', 'متوسط نسبة الغياب', Tile::percent($attendance['absent'], $attendance['planned']), Tile::PERCENT),
            ]),
        ];

        if ($employeeId !== null) {
            $mine = self::movementsByType($period, $employeeId);
            $myConduct = self::conductByCode($period, $employeeId);
            $myAttendance = self::attendance($period, $employeeId);
            $debts = (float) DB::table('employee_debts')->where('employee_id', $employeeId)->whereNull('deleted_at')
                ->where('remaining_amount', '>', 0)->sum('remaining_amount');

            $groups[] = Tile::group('employee', 'مؤشرات الموظف المحدد', [
                Tile::make('employee.leaves', 'إجازاته', $leaves($mine)),
                Tile::make('employee.leave_days', 'أيام إجازاته', $leaves($mine, 'days'), Tile::DAYS),
                Tile::make('employee.rewards_amount', 'مكافآته', Tile::money($code($mine, 'reward', 'amount')), Tile::MONEY),
                Tile::make('employee.deductions_amount', 'خصوماته', Tile::money($code($mine, 'deduction', 'amount')), Tile::MONEY),
                Tile::make('employee.net', 'صافي', Tile::money($code($mine, 'reward', 'amount') - $code($mine, 'deduction', 'amount')), Tile::MONEY),
                Tile::make('employee.movements', 'إجمالي حركاته', array_sum(array_column($mine, 'n'))),
            ]);
            $groups[] = Tile::group('employee_attendance', 'دوام وسلوك وديون الموظف المحدد', [
                Tile::make('employee.planned_days', 'أيام الدوام المقررة', $myAttendance['planned'], Tile::DAYS),
                Tile::make('employee.attended_days', 'أيام الحضور الفعلية', $myAttendance['attended'], Tile::DAYS),
                Tile::make('employee.absence_rate', 'نسبة الغياب', Tile::percent($myAttendance['absent'], $myAttendance['planned']), Tile::PERCENT),
                Tile::make('employee.warnings', 'إنذاراته', $myConduct['warning'] ?? 0),
                Tile::make('employee.complaints', 'شكاواه', $myConduct['complaint'] ?? 0),
                Tile::make('employee.debts_remaining', 'ديونه المتبقية', Tile::money($debts), Tile::MONEY),
            ]);
        }

        return ['period' => $period->toArray(), 'employee_id' => $employeeId, 'groups' => $groups];
    }

    /**
     * The movement types: which are leaves (in the list's order), the ids of each code, a coded item's ref.
     *
     * @return array{leaves: array<int, array{id: int, name_ar: string, archived: bool}>, codes: array<string, list<int>>, refs: array<string, array{id: int, name_ar: string, archived: bool}>}
     */
    public static function movementTypes(): array
    {
        $leaves = DB::table('movement_types AS t')->whereRaw(LeaveTypes::sql('t'), LeaveTypes::sqlBindings())->pluck('t.id')->map(fn ($id) => (int) $id)->all();
        $out = ['leaves' => [], 'codes' => [], 'refs' => []];

        foreach (DB::table('movement_types')->orderBy('sort_order')->orderBy('id')->get(['id', 'label', 'code', 'archived_at']) as $row) {
            $ref = ['id' => (int) $row->id, 'name_ar' => $row->label, 'archived' => $row->archived_at !== null];

            if (in_array((int) $row->id, $leaves, true)) {
                $out['leaves'][(int) $row->id] = $ref;
            }

            if ($row->code !== null) {
                $out['codes'][$row->code][] = (int) $row->id;
                $out['refs'][$row->code] = $ref;
            }
        }

        return $out;
    }

    /** @return array<int, array{n: int, amount: float, days: int}> type id => the kept movements' count, amounts and days */
    private static function movementsByType(?ReportPeriod $period, ?string $employeeId): array
    {
        return self::kept('employee_movements', $employeeId)
            ->when($period, fn (Builder $q) => $q->whereBetween('occurred_on', [$period->from(), $period->to()]))
            ->groupBy('movement_type_id')
            ->selectRaw('movement_type_id AS id, count(*) AS n, coalesce(sum(amount), 0) AS amount, coalesce(sum(days), 0) AS days')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->id => ['n' => (int) $r->n, 'amount' => (float) $r->amount, 'days' => (int) $r->days]])->all();
    }

    /** @return array<string, int> conduct type code => count */
    private static function conductByCode(ReportPeriod $period, ?string $employeeId): array
    {
        return self::kept('employee_conduct_records', $employeeId, 'c')
            ->join('conduct_types AS t', 't.id', '=', 'c.conduct_type_id')->whereNotNull('t.code')
            ->whereBetween('c.occurred_on', [$period->from(), $period->to()])
            ->groupBy('t.code')->selectRaw('t.code, count(*) AS n')->pluck('n', 'code')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array{planned: float, attended: float, absent: float} */
    private static function attendance(ReportPeriod $period, ?string $employeeId): array
    {
        $row = self::kept('employee_attendance', $employeeId)->where('year', $period->year)
            ->when($period->month, fn (Builder $q, int $month) => $q->where('month', $month))
            ->selectRaw('coalesce(sum(planned_days), 0) AS planned, coalesce(sum(attended_days), 0) AS attended, coalesce(sum(absent_days), 0) AS absent')
            ->first();

        return ['planned' => (float) $row->planned, 'attended' => (float) $row->attended, 'absent' => (float) $row->absent];
    }

    private static function kept(string $table, ?string $employeeId, ?string $alias = null): Builder
    {
        $prefix = $alias === null ? '' : $alias.'.';

        return DB::table($alias === null ? $table : "{$table} AS {$alias}")->whereNull($prefix.'deleted_at')
            ->when($employeeId, fn (Builder $q, string $id) => $q->where($prefix.'employee_id', $id));
    }

    /** The year's allowance («رصيد الإجازات»). */
    public static function allowance(): int
    {
        return (int) WorkSettings::current()->annual_leave_days;
    }
}
