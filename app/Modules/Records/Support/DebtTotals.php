<?php

namespace App\Modules\Records\Support;

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

    /** @return array{has_debts: bool, total_remaining: string} */
    public static function of(string $employeeId): array
    {
        $remaining = self::remaining([$employeeId])[$employeeId] ?? '0.00';

        return ['has_debts' => (float) $remaining > 0, 'total_remaining' => $remaining];
    }
}
