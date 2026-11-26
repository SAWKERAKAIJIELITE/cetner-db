<?php

namespace App\Modules\Reports\Support;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Support\EmployeeDerived;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * «لوحة التحكم» (D-051): the workbook's home tiles, the job statuses, the contracts and the fields most often
 * missing, for the approved files of a unit (with its sub-units) or of all. No period: the owner did not want one
 * (OD-26).
 */
final class OverviewReport
{
    /**
     * @param  array<string, mixed>  $filters  org_unit_id, include_sub_units
     * @return array<string, mixed>
     */
    public static function build(array $filters, int $missingLimit = 5): array
    {
        $today = BusinessDay::today()->format('Y-m-d');
        $in30 = BusinessDay::today()->addDays(30)->format('Y-m-d');
        $monthlyBefore = EmployeeScope::overdueBefore()['monthly'];
        $active = ListItems::id('employee_status', 'active');

        $row = DB::query()->fromSub(EmployeeScope::files($filters), 'e')
            ->leftJoinSub(EmployeeScope::debts(), 'd', 'd.employee_id', '=', 'e.id')
            ->leftJoinSub(EmployeeScope::reviews(), 'r', 'r.employee_id', '=', 'e.id')
            ->selectRaw(<<<'SQL'
                count(*) AS total,
                count(*) FILTER (WHERE e.employee_status_id = ?) AS active,
                avg(e.completeness) AS average_completeness,
                count(*) FILTER (WHERE r.monthly IS NULL OR r.monthly < ?) AS monthly_overdue,
                count(*) FILTER (WHERE e.contract_ends_on BETWEEN ?::date AND ?::date) AS ending,
                count(*) FILTER (WHERE e.contract_ends_on > ?::date) AS valid,
                count(*) FILTER (WHERE e.contract_ends_on < ?::date) AS expired,
                count(*) FILTER (WHERE e.contract_ends_on IS NULL) AS no_end_date,
                count(*) FILTER (WHERE d.remaining > 0) AS with_debts,
                count(*) FILTER (WHERE e.job_status_id IS NULL) AS job_status_unset
                SQL, [$active, $monthlyBefore, $today, $in30, $in30, $today])
            ->first();

        $total = (int) $row->total;

        $statusCounts = DB::query()->fromSub(EmployeeScope::files($filters), 'e')->whereNotNull('e.job_status_id')
            ->groupBy('e.job_status_id')->selectRaw('e.job_status_id AS id, count(*) AS n')->pluck('n', 'id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => (int) $n])->all();

        $statuses = array_map(fn (array $b) => [
            'item' => $b['item'],
            'count' => $b['count'],
            'share' => Tile::percent($b['count'], $total),
        ], ListItems::breakdown('job_status', $statusCounts));
        $statuses[] = ['item' => null, 'count' => (int) $row->job_status_unset, 'share' => Tile::percent((int) $row->job_status_unset, $total)];

        return [
            'total' => $total,
            'tiles' => [
                Tile::make('total', 'إجمالي الأفراد', $total),
                Tile::make('active', 'الموظفين الفعالين', (int) $row->active, Tile::COUNT, ListItems::ref('employee_status', $active)),
                Tile::make('average_completeness', 'متوسط إكتمال البيانات', $row->average_completeness === null ? null : round((float) $row->average_completeness, 2), Tile::PERCENT),
                Tile::make('monthly_overdue', 'متأخرو التحديث الشهري', (int) $row->monthly_overdue),
                Tile::make('contracts_ending', 'العقود المنتهية خلال 30 يوم', (int) $row->ending),
                Tile::make('with_debts', 'موظفون لديهم ديون', (int) $row->with_debts),
            ],
            'job_statuses' => $statuses,
            'contracts' => [
                'valid' => (int) $row->valid,
                'ending' => (int) $row->ending,
                'expired' => (int) $row->expired,
                'no_end_date' => (int) $row->no_end_date,
            ],
            'missing_fields' => self::missingFields($filters, $missingLimit),
        ];
    }

    /**
     * The counted fields most often empty, with how many files miss each (from the files' `missing_fields`).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, label: string, cycle: string, cycle_label: string, count: int, share: float}>
     */
    public static function missingFields(array $filters, int $limit): array
    {
        $total = (int) DB::query()->fromSub(EmployeeScope::files($filters), 'e')->count();
        $rows = DB::query()->fromSub(EmployeeScope::files($filters), 'e')
            ->crossJoin(DB::raw('jsonb_each(e.missing_fields) AS c(cycle, keys)'))
            ->crossJoin(DB::raw('jsonb_array_elements_text(c.keys) AS k(key)'))
            ->groupBy('k.key', 'c.cycle')
            ->selectRaw('k.key, c.cycle, count(*) AS n')
            ->orderByDesc('n')->orderBy('k.key')
            ->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'key' => $r->key,
            'label' => EmployeeDerived::label($r->key),
            'cycle' => $r->cycle,
            'cycle_label' => Cycle::from($r->cycle)->label(),
            'count' => (int) $r->n,
            'share' => Tile::percent((int) $r->n, $total),
        ])->values()->all();
    }
}
