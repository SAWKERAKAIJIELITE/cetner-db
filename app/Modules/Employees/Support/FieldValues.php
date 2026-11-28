<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Settings\Models\OrgUnit;
use App\Modules\Settings\Support\ListCatalogue;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The fields' values as the API takes them and as change sets store them (D-033): one normal form per type,
 * so that "8" and "8.00", " 0991 " and "٠٩٩١" are the same value. Errors are keyed `values.<field>`, named
 * with the field's Arabic label.
 */
final class FieldValues
{
    /**
     * Normalise and check submitted values. Unknown fields are refused; list items and units must be active.
     *
     * @param  array<string, mixed>  $values
     * @param  bool  $checkItems  false when the caller found the list items and units active already (the import, D-053)
     * @return array<string, mixed> field => normal value
     */
    public static function validate(array $values, bool $checkItems = true): array
    {
        $unknown = array_diff(array_keys($values), FieldCatalogue::keys());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => __('employees.unknown_fields', ['fields' => implode('، ', $unknown)])]);
        }

        $prepared = [];

        foreach ($values as $key => $value) {
            $prepared[$key] = self::prepare($key, $value);
        }

        $rules = [];
        $names = [];

        foreach (array_keys($prepared) as $key) {
            $rules['values.'.$key] = self::rules($key);
            $names['values.'.$key] = FieldCatalogue::get($key)['label'];
        }

        $validator = Validator::make(['values' => $prepared], $rules, [], $names);
        $validator->after(function ($validator) use ($prepared, $checkItems) {
            foreach ($checkItems ? $prepared : [] as $key => $value) {
                if ($value === null || $validator->errors()->has('values.'.$key)) {
                    continue;
                }

                $field = FieldCatalogue::get($key);

                if ($field['type'] === 'list' && ! self::activeListItem($field['list'], (int) $value)) {
                    $validator->errors()->add('values.'.$key, __('employees.list_item_unavailable', ['field' => $field['label']]));
                }

                if ($field['type'] === 'org_unit' && ! OrgUnit::query()->whereKey((int) $value)->whereNull('archived_at')->exists()) {
                    $validator->errors()->add('values.'.$key, __('employees.org_unit_unavailable', ['field' => $field['label']]));
                }
            }
        });

        $validator->validate();

        $normal = [];

        foreach ($prepared as $key => $value) {
            $normal[$key] = self::normal($key, $value);
        }

        return $normal;
    }

    /** Input cleaned before the rules: trimmed, Arabic digits as ASCII, empty as null. */
    private static function prepare(string $key, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

            if ($value === '') {
                return null;
            }
        }

        $type = FieldCatalogue::get($key)['type'];

        if (is_string($value) && in_array($type, ['digits', 'phone', 'int', 'year', 'rate', 'decimal', 'money', 'list', 'org_unit', 'date'], true)) {
            $value = ArabicText::digitsToAscii($value);
        }

        if (is_string($value) && $type === 'phone') {
            $value = preg_replace('/[\s\-()]/', '', $value);
        }

        if (is_string($value) && $type === 'digits') {
            $value = preg_replace('/\s/', '', $value);
        }

        if (is_string($value) && $type === 'email') {
            $value = mb_strtolower($value);
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function rules(string $key): array
    {
        $f = FieldCatalogue::get($key);
        $today = BusinessDay::today()->format('Y-m-d');

        return match ($f['type']) {
            'text', 'code' => ['nullable', 'string', 'max:'.$f['max']],
            'longtext' => ['nullable', 'string', 'max:'.$f['max']],
            'name' => ['nullable', 'string', 'max:100', 'not_regex:/[0-9٠-٩]/u'],
            'digits' => ['nullable', 'string', 'regex:/^[0-9]{'.$f['min'].','.$f['max'].'}$/'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'email' => ['nullable', 'string', 'max:150', 'email:rfc'],
            'date' => array_values(array_filter(['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.(($f['past'] ?? false) ? $today : '2200-12-31')])),
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'rate' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'int' => ['nullable', 'integer', 'between:'.$f['min'].','.$f['max']],
            'decimal' => ['nullable', 'numeric', 'decimal:0,'.$f['scale'], 'between:'.$f['min'].','.$f['max']],
            'money' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'bool' => ['nullable', 'boolean'],
            'list', 'org_unit' => ['nullable', 'integer', 'min:1'],
        };
    }

    /** The one form a value is compared and stored in. */
    public static function normal(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $f = FieldCatalogue::get($key);

        return match ($f['type']) {
            'int', 'year', 'list', 'org_unit' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'rate', 'money' => number_format((float) $value, 2, '.', ''),
            'decimal' => number_format((float) $value, $f['scale'], '.', ''),
            'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }

    /** The approved value of a field, in the normal form. */
    public static function current(Employee $employee, string $key): mixed
    {
        return self::normal($key, $employee->getAttribute($key));
    }

    private static function activeListItem(string $list, int $id): bool
    {
        return DB::table(ListCatalogue::table($list))->where('id', $id)->whereNull('archived_at')->exists();
    }
}
