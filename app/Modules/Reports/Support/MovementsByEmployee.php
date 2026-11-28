<?php

namespace App\Modules\Reports\Support;

use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Records\Support\LeaveTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «ملخص جميع الموظفين» of «داش بورد الحركات» (D-051): one row per approved file with its figures for the period,
 * the leave balance for the whole year (internal and external leave, D-044), and the workbook's «تقييم» from the
 * net of rewards and deductions. Four grouped subqueries joined to the page of files.
 */
final class MovementsByEmployee
{
    /** The orders a page may take. */
    public const SORTS = ['name', 'personal_number', 'net', 'movements', 'leave_days', 'absence_rate', 'warnings'];

    /** @param array<string, mixed> $f q, org_unit_id, include_sub_units, sort, direction */
    public static function query(ReportPeriod $period, array $f): Builder
    {
        $types = MovementsDashboard::movementTypes();
        $ids = fn (array $codes) => '{'.implode(',', array_merge(...array_map(fn ($c) => $types['codes'][$c] ?? [], $codes))).'}';
        $leaveIds = '{'.implode(',', array_keys($types['leaves'])).'}';
        $balanceIds = $ids(LeaveTypes::FROM_BALANCE);

        $movements = DB::table('employee_movements')->whereNull('deleted_at')
            ->whereBetween('occurred_on', [$period->from(), $period->to()])
            ->groupBy('employee_id')->select('employee_id')
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE movement_type_id = ANY(?::bigint[])) AS internal_leaves,
                count(*) FILTER (WHERE movement_type_id = ANY(?::bigint[])) AS external_leaves,
                coalesce(sum(days) FILTER (WHERE movement_type_id = ANY(?::bigint[])), 0) AS leave_days,
                count(*) FILTER (WHERE movement_type_id = ANY(?::bigint[])) AS rewards,
                coalesce(sum(amount) FILTER (WHERE movement_type_id = ANY(?::bigint[])), 0) AS rewards_amount,
                count(*) FILTER (WHERE movement_type_id = ANY(?::bigint[])) AS deductions,
                coalesce(sum(amount) FILTER (WHERE movement_type_id = ANY(?::bigint[])), 0) AS deductions_amount,
                count(*) FILTER (WHERE movement_type_id = ANY(?::bigint[])) AS transfers,
                count(*) AS movements
                SQL, [$ids(['leave_internal']), $ids(['leave_external']), $leaveIds, $ids(['reward']), $ids(['reward']),
                $ids(['deduction']), $ids(['deduction']), $ids(['transfer'])]);

        $balance = DB::table('employee_movements')->whereNull('deleted_at')
            ->whereBetween('occurred_on', [sprintf('%04d-01-01', $period->year), sprintf('%04d-12-31', $period->year)])
            ->whereRaw('movement_type_id = ANY(?::bigint[])', [$balanceIds])
            ->groupBy('employee_id')->select('employee_id')->selectRaw('coalesce(sum(days), 0) AS balance_days');

        $attendance = DB::table('employee_attendance')->whereNull('deleted_at')->where('year', $period->year)
            ->when($period->month, fn ($q, $m) => $q->where('month', $m))
            ->groupBy('employee_id')->select('employee_id')
            ->selectRaw('sum(planned_days) AS planned_days, sum(attended_days) AS attended_days, sum(absent_days) AS absent_days');

        $warnings = DB::table('employee_conduct_records AS c')->join('conduct_types AS t', 't.id', '=', 'c.conduct_type_id')
            ->whereNull('c.deleted_at')->where('t.code', 'warning')
            ->whereBetween('c.occurred_on', [$period->from(), $period->to()])
            ->groupBy('c.employee_id')->select('c.employee_id')->selectRaw('count(*) AS warnings');

        $allowance = MovementsDashboard::allowance();
        $sort = in_array($f['sort'] ?? null, self::SORTS, true) ? $f['sort'] : 'name';
        $direction = ($f['direction'] ?? ($sort === 'name' || $sort === 'personal_number' ? 'asc' : 'desc')) === 'desc' ? 'DESC' : 'ASC';
        $order = match ($sort) {
            'personal_number' => 'employees.personal_number',
            'net' => 'net',
            'movements' => 'movements',
            'leave_days' => 'leave_days',
            'absence_rate' => 'absence_rate',
            'warnings' => 'warnings',
            default => 'employees.search_name',
        };

        return EmployeeQuery::build(array_intersect_key($f, array_flip(['q', 'org_unit_id', 'include_sub_units'])) + ['file_status' => 'active'])
            ->reorder()
            ->leftJoinSub($movements, 'mv', 'mv.employee_id', '=', 'employees.id')
            ->leftJoinSub($balance, 'bal', 'bal.employee_id', '=', 'employees.id')
            ->leftJoinSub($attendance, 'att', 'att.employee_id', '=', 'employees.id')
            ->leftJoinSub($warnings, 'cw', 'cw.employee_id', '=', 'employees.id')
            ->select('employees.*')
            ->selectRaw(<<<'SQL'
                coalesce(mv.internal_leaves, 0) AS internal_leaves,
                coalesce(mv.external_leaves, 0) AS external_leaves,
                coalesce(mv.leave_days, 0) AS leave_days,
                ? - coalesce(bal.balance_days, 0) AS leave_balance,
                coalesce(mv.rewards, 0) AS rewards,
                coalesce(mv.rewards_amount, 0) AS rewards_amount,
                coalesce(mv.deductions, 0) AS deductions,
                coalesce(mv.deductions_amount, 0) AS deductions_amount,
                coalesce(mv.transfers, 0) AS transfers,
                coalesce(mv.rewards_amount, 0) - coalesce(mv.deductions_amount, 0) AS net,
                coalesce(mv.movements, 0) AS movements,
                coalesce(att.planned_days, 0) AS planned_days,
                coalesce(att.attended_days, 0) AS attended_days,
                CASE WHEN coalesce(att.planned_days, 0) > 0 THEN round(att.absent_days * 100 / att.planned_days, 2) ELSE 0 END AS absence_rate,
                coalesce(cw.warnings, 0) AS warnings
                SQL, [$allowance])
            ->orderByRaw("{$order} {$direction} NULLS LAST")->orderBy('employees.id');
    }

    /** @return array{0: string, 1: string} «تقييم»: positive, negative or neutral, and how it reads */
    public static function rating(float $net): array
    {
        return match (true) {
            $net > 0 => ['positive', 'إيجابي'],
            $net < 0 => ['negative', 'سلبي'],
            default => ['neutral', 'متعادل'],
        };
    }
}
