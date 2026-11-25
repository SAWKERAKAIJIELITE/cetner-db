<?php

namespace App\Modules\Records\Support;

use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * The file's two debt columns, «عليه ديون» and «إجمالي الديون المتبقية» (D-044): computed from the debts record
 * when read, never stored, so they cannot drift from it.
 */
final class DebtTotals
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, string> employee id => what is left to pay, for those with something left
     */
    public static function remaining(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return DB::table('employee_debts')
            ->whereIn('employee_id', $employeeIds)->whereNull('deleted_at')->where('remaining_amount', '>', 0)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, sum(remaining_amount) AS remaining')
            ->pluck('remaining', 'employee_id')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->all();
    }

    /**
     * The file's debt columns and the finance card's counts: open debts (something left) and late ones (left, past
     * their due date).
     *
     * @return array{has_debts: bool, total_remaining: string, open_debts: int, overdue_debts: int}
     */
    public static function of(string $employeeId): array
    {
        $row = DB::table('employee_debts')->where('employee_id', $employeeId)->whereNull('deleted_at')->where('remaining_amount', '>', 0)
            ->selectRaw('coalesce(sum(remaining_amount), 0) AS remaining, count(*) AS open, count(*) FILTER (WHERE due_on < ?) AS overdue', [BusinessDay::today()->format('Y-m-d')])
            ->first();

        return [
            'has_debts' => (float) $row->remaining > 0,
            'total_remaining' => number_format((float) $row->remaining, 2, '.', ''),
            'open_debts' => (int) $row->open,
            'overdue_debts' => (int) $row->overdue,
        ];
    }
}
