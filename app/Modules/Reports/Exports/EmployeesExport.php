<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeComputed;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldDefinitions;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Records\Support\DebtTotals;
use App\Modules\Reports\Support\EmployeeScope;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «قاعدة بيانات الموظفين» as an Excel file (D-052): the approved files of the employees list's filters and order,
 * every field of the field guide in its order with the value as people read it, then the workbook's formulas.
 * 500 files at a time, each page with a fixed number of queries.
 */
final class EmployeesExport
{
    private const PAGE = 500;

    /** @param array<string, mixed> $filters */
    public static function query(array $filters): Builder
    {
        return EmployeeQuery::build(array_merge($filters, ['file_status' => 'active']));
    }

    /** @return list<string> */
    public static function headers(): array
    {
        $headers = ['الرقم الذاتي', 'الاسم الثلاثي'];

        foreach (FieldDefinitions::fields() as $key => $definition) {
            if ($key !== 'personal_number') {
                $headers[] = $definition->label_ar;
            }
        }

        array_push($headers, 'العمر', 'سنوات الخدمة', 'المتبقي على انتهاء العقد (يوم)', 'عليه ديون', 'إجمالي الديون المتبقية',
            'عدد الوثائق المرفقة (من '.count(DocumentCatalogue::FILE_KINDS).')', 'نسبة اكتمال البيانات', 'عدد الحقول الناقصة');

        foreach (Cycle::reviewed() as $cycle) {
            $headers[] = 'آخر مراجعة - الدورة '.$cycle->label();
        }

        $headers[] = 'تاريخ التقديم';

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Generator<list<string|int|float|null>>
     */
    public static function rows(array $filters): \Generator
    {
        $fields = array_values(array_filter(array_keys(FieldDefinitions::fields()->all()), fn ($k) => $k !== 'personal_number'));
        $labels = new ValueLabels;
        $page = 1;

        do {
            $batch = self::query($filters)->forPage($page++, self::PAGE)->get();
            $ids = $batch->pluck('id')->all();
            $debts = DebtTotals::remaining($ids);
            $reviews = $ids === [] ? collect() : EmployeeScope::reviews()->whereIn('employee_id', $ids)->get()->keyBy('employee_id');
            $documents = $ids === [] ? [] : DB::table('employee_documents')->whereIn('employee_id', $ids)->whereNull('conduct_record_id')
                ->where('status', 'approved')->whereNull('superseded_at')->whereNull('removed_at')
                ->groupBy('employee_id')->selectRaw('employee_id, count(*) AS n')->pluck('n', 'employee_id')->all();

            foreach ($batch as $employee) {
                /** @var Employee $employee */
                foreach ($fields as $key) {
                    $labels->add($key, $employee->getAttribute($key));
                }

                $row = [$employee->personal_number, $employee->fullName()];

                foreach ($fields as $key) {
                    $row[] = self::cell($labels, $key, FieldValues::current($employee, $key));
                }

                array_push($row,
                    EmployeeComputed::age($employee),
                    EmployeeComputed::serviceYears($employee),
                    EmployeeComputed::contractDaysLeft($employee),
                    isset($debts[$employee->id]) ? 'نعم' : 'لا',
                    (float) ($debts[$employee->id] ?? 0),
                    (int) ($documents[$employee->id] ?? 0),
                    (float) $employee->completeness,
                    collect($employee->missing_fields ?? [])->sum(fn ($keys) => count($keys)),
                );

                foreach (Cycle::reviewed() as $cycle) {
                    $last = $reviews->get($employee->id)?->{$cycle->value};
                    $row[] = $last === null ? null : substr((string) $last, 0, 10);
                }

                $row[] = $employee->activated_at?->setTimezone(BusinessDay::timezone())->format('Y-m-d');

                yield $row;
            }
        } while ($batch->count() === self::PAGE);
    }

    private static function cell(ValueLabels $labels, string $key, mixed $value): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match (FieldCatalogue::get($key)['type']) {
            'int', 'year' => (int) $value,
            'rate', 'money', 'decimal' => (float) $value,
            'list', 'org_unit', 'bool' => $labels->display($key, $value),
            default => (string) $value,
        };
    }
}
