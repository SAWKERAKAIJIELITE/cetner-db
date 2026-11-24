<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Support\ArabicText;

/**
 * What is derived from the approved values and stored with them (D-035), so that lists filter and sort on it:
 * the name's search key, the completeness (the workbook's «نسبة اكتمال البيانات») and the missing counted
 * fields by cycle («الحقول الناقصة حسب دورة التحديث»). Refreshed with every approved change and when the
 * definitions change.
 */
final class EmployeeDerived
{
    public static function refresh(Employee $employee): void
    {
        $name = trim(implode(' ', array_filter([
            $employee->first_name, $employee->father_name, $employee->family_name, $employee->nickname,
        ])));

        $employee->search_name = $name === '' ? null : ArabicText::searchKey($name);

        [$completeness, $missing] = self::completeness($employee);
        $employee->completeness = $completeness;
        $employee->missing_fields = $missing;
    }

    /**
     * @return array{0: string, 1: array<string, list<string>>} percent with two decimals; cycle => missing keys
     */
    public static function completeness(Employee $employee): array
    {
        $counted = FieldDefinitions::counted();

        if ($counted === []) {
            return ['100.00', []];
        }

        $missing = [];
        $filled = 0;

        foreach ($counted as $key) {
            $value = $employee->getAttribute($key);

            if ($value === null || $value === '') {
                $missing[FieldDefinitions::all()[$key]->cycle->value][] = $key;
            } else {
                $filled++;
            }
        }

        // In the cycles' order.
        $ordered = [];

        foreach (Cycle::cases() as $cycle) {
            if (isset($missing[$cycle->value])) {
                $ordered[$cycle->value] = $missing[$cycle->value];
            }
        }

        return [number_format($filled * 100 / count($counted), 2, '.', ''), $ordered];
    }
}
