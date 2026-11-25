<?php

namespace App\Modules\Records\Enums;

/** The workbook's «حالة الدين»: computed, never entered (D-042). */
enum DebtStatus: string
{
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Outstanding = 'outstanding';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'مسدد',
            self::Overdue => 'متأخر',
            self::Outstanding => 'قائم',
        };
    }
}
