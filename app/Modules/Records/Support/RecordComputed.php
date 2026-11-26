<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;

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
            $rate = self::absenceRate((float) $record->getAttribute('planned_days'), (float) $record->getAttribute('absent_days'));

            return [
                'absent_days' => (float) $record->getAttribute('absent_days'),
                /** 0 to 1 */
                'absence_rate' => $rate,
                /** At or above the branch's threshold («نسبة الغياب المرتفعة», 20% by default). */
                'high_absence' => $rate >= self::highAbsenceRate(),
            ];
        }

        if ($record instanceof EmployeeDebt) {
            $remaining = (float) $record->getAttribute('remaining_amount');
            $status = self::debtStatus($remaining, $record->getAttribute('due_on')?->format('Y-m-d'));
            $installment = (float) $record->getAttribute('monthly_installment');

            return [
                /** «المبلغ المسدد» in all: paid when entered plus the payments (D-045). */
                'paid_total' => self::money((float) $record->getAttribute('paid_amount') + (float) $record->getAttribute('payments_total')),
                'payments_total' => self::money((float) $record->getAttribute('payments_total')),
                'remaining_amount' => self::money($remaining),
                /** What is left ÷ the monthly instalment, rounded up; null without an instalment. */
                'installments_left' => $installment > 0 ? (int) ceil(round($remaining / $installment, 6)) : null,
                'status' => $status->value,
                'status_label' => $status->label(),
            ];
        }

        $date = $record->getAttribute($record->recordType()->dateColumn());
        $computed = ['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n')];

        if ($record instanceof EmployeeMovement) {
            /** Calendar days from «من تاريخ» to «إلى تاريخ», both included (owner, 25 Nov). */
            $computed['days'] = $record->getAttribute('days') === null ? null : (int) $record->getAttribute('days');
            /** The file fields a movement of this type updates (D-048); empty for a leave, a reward, a course… */
            $computed['file_fields'] = MovementEffects::fields((int) $record->getAttribute('movement_type_id'));
            /** When the file took it: null until approved and due. */
            $computed['applied_to_file_at'] = $record->getAttribute('applied_to_file_at') === null ? null
                : Carbon::parse($record->getAttribute('applied_to_file_at'))->toIso8601String();
            /** The file's change that did it (its history); null when nothing differed. */
            $computed['file_change_set_id'] = $record->getAttribute('file_change_set_id');
        }

        return $computed;
    }

    /** The workbook: absent ÷ planned, 0 when nothing was planned; four decimals. */
    public static function absenceRate(float $planned, float $absent): float
    {
        return $planned <= 0 ? 0.0 : round($absent / $planned, 4);
    }

    /** Set by RecordPage for one page: the threshold is read once, not per record. */
    public static ?float $threshold = null;

    /** The branch's threshold as a rate (0.2 for 20%). */
    public static function highAbsenceRate(): float
    {
        return self::$threshold ?? self::thresholdFromSettings();
    }

    public static function thresholdFromSettings(): float
    {
        return (float) WorkSettings::current()->getAttribute('high_absence_rate') / 100;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
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
