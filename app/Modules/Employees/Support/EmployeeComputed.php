<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Settings\Support\ListCatalogue;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The workbook's formulas that depend on today: computed when read, never stored (D-035).
 * Today is the Damascus day (D-012).
 */
final class EmployeeComputed
{
    /** «العمر»: whole years. */
    public static function age(Employee $employee): ?int
    {
        return $employee->birth_date === null ? null : (int) CarbonImmutable::instance($employee->birth_date)->diffInYears(BusinessDay::today());
    }

    /**
     * «سنوات الخدمة»: from the earliest of assignment, appointment and contract signature, to the discharge date
     * or today, in years with one decimal (the workbook's formula).
     */
    public static function serviceYears(Employee $employee): ?float
    {
        $starts = array_filter([$employee->assignment_date, $employee->appointment_date, $employee->contract_signed_on]);

        if ($starts === []) {
            return null;
        }

        $from = CarbonImmutable::instance(min($starts));
        $to = $employee->discharge_date !== null ? CarbonImmutable::instance($employee->discharge_date) : BusinessDay::today();

        return round($from->diffInDays($to, false) / 365.25, 1);
    }

    /** «المتبقي على انتهاء العقد (يوم)»: negative once it has ended. */
    public static function contractDaysLeft(Employee $employee): ?int
    {
        return $employee->contract_ends_on === null
            ? null
            : (int) BusinessDay::today()->diffInDays(CarbonImmutable::instance($employee->contract_ends_on), false);
    }

    /** The code of a system item a list field holds (D-025), or null. */
    public static function code(Employee $employee, string $field): ?string
    {
        $id = $employee->getAttribute($field);

        if ($id === null) {
            return null;
        }

        return DB::table(ListCatalogue::table(FieldCatalogue::get($field)['list']))->where('id', $id)->value('code');
    }
}
