<?php

namespace App\Modules\Records\Enums;

use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeConductRecord;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Records\Models\EmployeeDebtPayment;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Models\EmployeeRecord;

/** The workbook's four records (D-040), and the debts' payments, entries of their own (D-045). */
enum RecordType: string
{
    case Movements = 'movements';
    case Attendance = 'attendance';
    case Conduct = 'conduct';
    case Debts = 'debts';
    case Payments = 'payments';

    public function label(): string
    {
        return match ($this) {
            self::Movements => 'سجل الحركات',
            self::Attendance => 'سجل الدوام الشهري',
            self::Conduct => 'سجل السلوك الوظيفي',
            self::Debts => 'سجل الديون',
            self::Payments => 'دفعات الديون',
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
            self::Payments => EmployeeDebtPayment::class,
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
            self::Payments => ['paid_on'],
            default => ['occurred_on'],
        };
    }

    /** The date a record falls on, for the period filters; the attendance is by year and month. */
    public function dateColumn(): ?string
    {
        return match ($this) {
            self::Attendance => null,
            self::Debts => 'incurred_on',
            self::Payments => 'paid_on',
            default => 'occurred_on',
        };
    }

    /** A payment is never corrected, only removed (D-045). */
    public function correctable(): bool
    {
        return $this !== self::Payments;
    }

    /** The columns the database computes, never written. */
    public function generated(): array
    {
        return match ($this) {
            self::Movements => ['days'],
            self::Attendance => ['absent_days'],
            self::Debts => ['payments_total', 'remaining_amount'],
            default => [],
        };
    }
}
