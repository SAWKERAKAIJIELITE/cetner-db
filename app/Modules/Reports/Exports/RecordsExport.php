<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordComputed;
use App\Modules\Records\Support\RecordLabels;
use App\Modules\Records\Support\RecordRegister;

/**
 * A register as an Excel file (D-052): the register's filters and order (RecordRegister), so what is exported is
 * what the screen lists, waiting additions marked «قيد التدقيق»; every column of the record with the value as
 * people read it, then its computed columns. 1,000 records at a time.
 */
final class RecordsExport
{
    private const PAGE = 1000;

    /** @return list<string> */
    public static function headers(RecordType $type): array
    {
        $headers = ['الرقم الذاتي', 'الاسم الثلاثي'];

        foreach (RecordCatalogue::fields($type) as $field) {
            $headers[] = $field['label'];
        }

        return [...$headers, ...array_values(self::computedHeaders($type)), 'حالة الاعتماد'];
    }

    /** @return array<string, string> the computed columns: key => header */
    private static function computedHeaders(RecordType $type): array
    {
        return match ($type) {
            RecordType::Movements => ['days' => 'عدد الأيام'],
            RecordType::Attendance => ['absent_days' => 'أيام الغياب', 'absence_rate' => 'نسبة الغياب'],
            RecordType::Debts => ['paid_total' => 'المبلغ المسدد', 'remaining_amount' => 'المبلغ المتبقي', 'installments_left' => 'الأقساط المتبقية', 'status_label' => 'حالة الدين'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters  the register's
     * @return \Generator<list<string|int|float|null>>
     */
    public static function rows(RecordType $type, array $filters): \Generator
    {
        $fields = RecordCatalogue::fields($type);
        $computed = self::computedHeaders($type);
        $page = 1;

        do {
            $batch = RecordRegister::query($type, $filters)->with('employee')->forPage($page++, self::PAGE)->get();
            $labels = (new RecordLabels)->prime($type, $batch->map->getAttributes());
            $unitLabels = new ValueLabels;
            RecordComputed::$threshold = RecordComputed::thresholdFromSettings();

            foreach ($batch as $record) {
                $row = [$record->employee?->personal_number, $record->employee?->fullName()];

                foreach ($fields as $key => $field) {
                    $value = $record->getAttribute($key);
                    $row[] = match (true) {
                        $value === null => null,
                        $field['type'] === 'unit' => $unitLabels->unitPath((int) $value),
                        in_array($field['type'], ['int'], true) => (int) $value,
                        in_array($field['type'], ['days', 'decimal', 'money'], true) => (float) $value,
                        $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                        default => $labels->display($type, $key, $value),
                    };
                }

                $values = RecordComputed::of($record);

                foreach (array_keys($computed) as $key) {
                    $v = $values[$key] ?? null;
                    $row[] = match (true) {
                        $v === null => null,
                        $key === 'absence_rate' => round((float) $v * 100, 2),
                        is_numeric($v) && $key !== 'status_label' => (float) $v,
                        default => (string) $v,
                    };
                }

                $row[] = ($record->getAttribute('review_status') ?? 'approved') === 'pending' ? 'قيد التدقيق' : 'معتمد';

                yield $row;
            }
        } while ($batch->count() === self::PAGE);
    }
}
