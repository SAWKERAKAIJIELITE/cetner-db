<?php

namespace App\Modules\Imports\Enums;

use App\Modules\Records\Enums\RecordType;

/** The workbook's sheets an import reads (D-053): the employees and the four registers, each if present. */
enum ImportSheet: string
{
    case Employees = 'employees';
    case Movements = 'movements';
    case Attendance = 'attendance';
    case Conduct = 'conduct';
    case Debts = 'debts';

    /** The sheet's name in the workbook. */
    public function sheetName(): string
    {
        return match ($this) {
            self::Employees => 'قاعدة بيانات الموظفين',
            self::Movements => 'سجل الحركات',
            self::Attendance => 'سجل الدوام الشهري',
            self::Conduct => 'سجل السلوك الوظيفي',
            self::Debts => 'سجل الديون',
        };
    }

    public function label(): string
    {
        return $this->sheetName();
    }

    public function recordType(): ?RecordType
    {
        return match ($this) {
            self::Employees => null,
            self::Movements => RecordType::Movements,
            self::Attendance => RecordType::Attendance,
            self::Conduct => RecordType::Conduct,
            self::Debts => RecordType::Debts,
        };
    }
}
