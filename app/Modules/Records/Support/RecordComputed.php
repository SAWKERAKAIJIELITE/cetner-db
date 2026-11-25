<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Records\Models\EmployeeRecord;
use App\Support\BusinessDay;

/**
 * The workbook's formula columns of a record (D-042): the absent days and the absence rate of a month, what is
 * left of a debt and its status, the year and month of a dated record. Never entered.
 */
final class RecordComputed
{
    /** @return array<string, mixed> */
    public static function of(EmployeeRecord $record): array
    {
        if ($record instanceof EmployeeAttendance) {
            $rate = self::absenceRate((int) $record->getAttribute('planned_days'), (int) $record->getAttribute('absent_days'));

            return [
                'absent_days' => (int) $record->getAttribute('absent_days'),
                /** 0 to 1 */
                'absence_rate' => $rate,
                'high_absence' => $rate >= (float) config('hr.high_absence_rate'),
            ];
        }

        if ($record instanceof EmployeeDebt) {
            $status = self::debtStatus((float) $record->getAttribute('remaining_amount'), $record->getAttribute('due_on')?->format('Y-m-d'));

            return [
                'remaining_amount' => number_format((float) $record->getAttribute('remaining_amount'), 2, '.', ''),
                'status' => ['value' => $status->value, 'label' => $status->label()],
            ];
        }

        $date = $record->getAttribute('occurred_on');

        return ['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n')];
    }

    /** The workbook: absent ÷ planned, 0 when nothing was planned; four decimals. */
    public static function absenceRate(int $planned, int $absent): float
    {
        return $planned === 0 ? 0.0 : round($absent / $planned, 4);
    }

    /** The workbook: paid when nothing is left, late when its due date has passed, otherwise outstanding. */
    public static function debtStatus(float $remaining, ?string $dueOn): DebtStatus
    {
        if ($remaining <= 0) {
            return DebtStatus::Paid;
        }

        return $dueOn !== null && $dueOn < BusinessDay::today()->format('Y-m-d') ? DebtStatus::Overdue : DebtStatus::Outstanding;
    }
}
