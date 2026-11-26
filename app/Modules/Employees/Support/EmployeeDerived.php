<?php

namespace App\Modules\Employees\Support;

use App\Modules\Documents\Support\DocumentQueries;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

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
        $definitions = FieldDefinitions::all();
        $attached = self::attachedKinds($employee, $counted);

        foreach ($counted as $key) {
            // An attachment (D-049) is filled when a current approved file of its kind is attached.
            $value = $definitions[$key]->type === FieldDefinitions::DOCUMENT
                ? (in_array($key, $attached, true) ? true : null)
                : $employee->getAttribute($key);

            if ($value === null || $value === '') {
                $missing[FieldDefinitions::all()[$key]->cycle->value][] = $key;
            } else {
                $filled++;
            }

            // The workbook counts «المستوى التنظيمي الأول» and «الثاني» apart (73 ✔): one unit is two
            // counted fields, the second filled when the unit is at level 2 or deeper.
            if ($key === 'org_unit_id') {
                if ($value !== null && self::unitLevel((int) $value) >= 2) {
                    $filled++;
                } else {
                    $missing[FieldDefinitions::all()[$key]->cycle->value][] = self::SECOND_LEVEL;
                }
            }
        }

        // In the cycles' order.
        $ordered = [];

        foreach (Cycle::cases() as $cycle) {
            if (isset($missing[$cycle->value])) {
                $ordered[$cycle->value] = $missing[$cycle->value];
            }
        }

        return [number_format($filled * 100 / self::countedTotal(), 2, '.', ''), $ordered];
    }

    /** The second organisational level, counted apart as in the workbook. */
    public const SECOND_LEVEL = 'org_unit_level_2';

    /** How many counted fields: the counted definitions (the five counted attachments too), the unit counting twice (the workbook's 73). */
    public static function countedTotal(): int
    {
        $counted = FieldDefinitions::counted();

        return count($counted) + (in_array('org_unit_id', $counted, true) ? 1 : 0);
    }

    /** A missing field's label, the second level's too. */
    public static function label(string $key): string
    {
        return $key === self::SECOND_LEVEL ? 'المستوى التنظيمي الثاني' : FieldDefinitions::all()[$key]->label_ar;
    }

    /**
     * The kinds of the employee's current approved files, read only when an attachment counts.
     *
     * @param  list<string>  $counted
     * @return list<string>
     */
    private static function attachedKinds(Employee $employee, array $counted): array
    {
        $documents = FieldDefinitions::all()->only($counted)->filter(fn ($d) => $d->type === FieldDefinitions::DOCUMENT);

        if ($documents->isEmpty() || ! $employee->exists) {
            return [];
        }

        return DocumentQueries::currentKinds($employee->getKey());
    }

    private static function unitLevel(int $id): int
    {
        return (int) DB::table('org_units')->where('id', $id)->value('level');
    }
}
