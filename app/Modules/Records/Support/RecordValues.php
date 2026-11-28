<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Settings\Support\ListCatalogue;
use App\Support\ArabicText;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A record's values as the API takes them and as changes store them: one normal form per type, as for the file's
 * fields (D-033), so "1500" and "1500.00", "٢٠" and "20" are one value. Errors are keyed under the caller's
 * prefix (`values` for one change, `changes.3.values` in a batch), named with the workbook's label.
 */
final class RecordValues
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> field => normal value
     */
    /** `$checkItems`: false when the caller found the list items and units active already (the import, D-053). */
    public static function validate(RecordType $type, array $values, string $prefix = 'values', bool $checkItems = true): array
    {
        $fields = RecordCatalogue::fields($type);
        $unknown = array_diff(array_keys($values), array_keys($fields));

        if ($unknown !== []) {
            throw ValidationException::withMessages([$prefix => __('records.unknown_fields', ['fields' => implode('، ', $unknown)])]);
        }

        $prepared = [];

        foreach ($values as $key => $value) {
            $prepared[$key] = self::prepare($fields[$key], $value);
        }

        $rules = [];
        $names = [];

        foreach (array_keys($prepared) as $key) {
            $rules['v.'.$key] = self::rules($fields[$key]);
            $names['v.'.$key] = $fields[$key]['label'];
        }

        $validator = Validator::make(['v' => $prepared], $rules, [], $names);
        $validator->after(function ($validator) use ($prepared, $fields, $checkItems) {
            foreach ($checkItems ? $prepared : [] as $key => $value) {
                if ($value === null || $validator->errors()->has('v.'.$key) || ! in_array($fields[$key]['type'], ['list', 'unit'], true)) {
                    continue;
                }

                $table = $fields[$key]['type'] === 'unit' ? 'org_units' : ListCatalogue::table($fields[$key]['list']);

                if (! DB::table($table)->where('id', (int) $value)->whereNull('archived_at')->exists()) {
                    $validator->errors()->add('v.'.$key, __('records.list_item_unavailable', ['field' => $fields[$key]['label']]));
                }
            }
        });

        if ($validator->fails()) {
            $errors = [];

            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[$prefix.substr($key, 1)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $normal = [];

        foreach ($prepared as $key => $value) {
            $normal[$key] = self::normal($fields[$key], $value);
        }

        return $normal;
    }

    /**
     * The rules between fields, on the record as it would be: the approved values with the changes over them.
     * `$paymentsTotal`: a debt's payments so far (D-045).
     *
     * @param  array<string, mixed>  $record  field => normal value
     */
    public static function ensureConsistent(RecordType $type, array $record, string $prefix = 'values', float $paymentsTotal = 0, bool $targets = true): void
    {
        $errors = [];
        $v = fn (string $key) => $record[$key] ?? null;

        if ($type === RecordType::Movements) {
            if (($v('starts_on') === null) !== ($v('ends_on') === null)) {
                $errors[$prefix.'.'.($v('starts_on') === null ? 'starts_on' : 'ends_on')] = __('records.period_both_ends');
            } elseif ($v('starts_on') !== null && $v('ends_on') < $v('starts_on')) {
                $errors[$prefix.'.ends_on'] = __('records.ends_before_start');
            } elseif ($v('starts_on') !== null && CarbonImmutable::parse($v('starts_on'))->diffInDays(CarbonImmutable::parse($v('ends_on'))) >= 400) {
                $errors[$prefix.'.ends_on'] = __('records.period_too_long');
            }

            // What the movement makes of the file, by its type (D-048).
            // An imported movement is history and changes nothing (D-053): the workbook has no targets.
            if ($targets && $v('movement_type_id') !== null) {
                $errors += MovementEffects::check((int) $v('movement_type_id'), $record, $prefix);
            }

            // A leave is a period: its days are counted from it (D-042, owner 25 Nov).
            if ($v('movement_type_id') !== null && $v('starts_on') === null && LeaveTypes::isLeave((int) $v('movement_type_id'))) {
                $errors[$prefix.'.starts_on'] = __('records.leave_needs_period');
            }
        }

        if ($type === RecordType::Attendance && $v('planned_days') !== null) {
            if ($v('attended_days') !== null && $v('attended_days') > $v('planned_days')) {
                $errors[$prefix.'.attended_days'] = __('records.attended_over_planned');
            }

            if ($v('year') !== null && $v('month') !== null) {
                $days = CarbonImmutable::create($v('year'), $v('month'), 1)->daysInMonth;

                if ($v('planned_days') > $days) {
                    $errors[$prefix.'.planned_days'] = __('records.planned_over_month', ['days' => $days]);
                }
            }
        }

        if ($type === RecordType::Debts && $v('amount') !== null && (float) ($v('paid_amount') ?? 0) + $paymentsTotal > (float) $v('amount')) {
            // With payments, the debt's amount is what is too small; otherwise the amount paid when entered too big.
            $errors[$prefix.'.'.($paymentsTotal > 0 ? 'amount' : 'paid_amount')] = $paymentsTotal > 0
                ? __('records.debt_under_payments', ['paid' => number_format((float) ($v('paid_amount') ?? 0) + $paymentsTotal, 2, '.', '')])
                : __('records.paid_over_amount');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Every field of an approved record, in the normal form.
     *
     * @return array<string, mixed>
     */
    public static function of(EmployeeRecord $record): array
    {
        $values = [];

        foreach (RecordCatalogue::fields($record->recordType()) as $key => $field) {
            $values[$key] = self::normal($field, $record->getAttribute($key));
        }

        return $values;
    }

    private static function prepare(array $field, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);

            if ($value === '') {
                return null;
            }

            if (! in_array($field['type'], ['text', 'longtext'], true)) {
                $value = ArabicText::digitsToAscii($value);
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function rules(array $field): array
    {
        $required = ($field['required'] ?? false) ? 'required' : 'nullable';

        return match ($field['type']) {
            'text', 'longtext' => [$required, 'string', 'max:'.$field['max']],
            'date' => [$required, 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2200-12-31'],
            'int' => [$required, 'integer', 'between:'.$field['min'].','.$field['max']],
            'days' => [$required, 'numeric', 'decimal:0,1', 'between:'.$field['min'].','.$field['max']],
            'debt' => [$required, 'uuid'],
            'unit' => [$required, 'integer', 'min:1'],
            'decimal' => [$required, 'numeric', 'decimal:0,'.$field['scale'], 'between:'.$field['min'].','.$field['max']],
            'money' => [$required, 'numeric', 'decimal:0,2', ($field['positive'] ?? false) ? 'gt:0' : 'min:0', 'max:999999999999'],
            'list' => [$required, 'integer', 'min:1'],
        };
    }

    public static function normal(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field['type']) {
            'int', 'list', 'unit' => (int) $value,
            'days' => (float) round((float) $value, 1),
            'money' => number_format((float) $value, 2, '.', ''),
            'decimal' => number_format((float) $value, $field['scale'], '.', ''),
            'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }
}
