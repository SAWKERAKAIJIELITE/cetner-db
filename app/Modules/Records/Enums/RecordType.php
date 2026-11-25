<?php

namespace App\Modules\Records\Enums;

use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeConductRecord;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Models\EmployeeRecord;

/** The workbook's four records (D-040). */
enum RecordType: string
{
    case Movements = 'movements';
    case Attendance = 'attendance';
    case Conduct = 'conduct';
    case Debts = 'debts';

    public function label(): string
    {
        return match ($this) {
            self::Movements => 'سجل الحركات',
            self::Attendance => 'سجل الدوام الشهري',
            self::Conduct => 'سجل السلوك الوظيفي',
            self::Debts => 'سجل الديون',
        };
    }

    /** @return class-string<EmployeeRecord> */
    public function model(): string
    {
        return match ($this) {
            self::Movements => EmployeeMovement::class,
            self::Attendance => EmployeeAttendance::class,
            self::Conduct => EmployeeConductRecord::class,
            self::Debts => EmployeeDebt::class,
        };
    }

    public function table(): string
    {
        return (new ($this->model()))->getTable();
    }

    /** Newest first. */
    public function order(): array
    {
        return match ($this) {
            self::Attendance => ['year', 'month'],
            self::Debts => ['incurred_on'],
            default => ['occurred_on'],
        };
    }
}
