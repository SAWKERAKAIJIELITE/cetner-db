<?php

namespace App\Modules\Imports\Support;

use App\Support\ArabicText;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;

/** A cell as the import reads it (D-053): the value typed, or a formula's last computed value. */
final class ImportCell
{
    public static function value(Cell $cell): mixed
    {
        $value = $cell instanceof Cell\FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /** How the cell reads, for the preview and the messages. */
    public static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'نعم' : 'لا',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_float($value) => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'),
            default => trim((string) $value),
        };
    }

    /**
     * A date: an Excel date, its serial number, or text as Y-m-d, Y/m/d, d/m/Y, d-m-Y or d.m.Y (the day first, as
     * in Syria). Anything else is returned as it is, for the field's rules to refuse with its name.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            return $value >= 1 && $value < 2958466
                ? CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($value))->format('Y-m-d')
                : (string) $value;
        }

        $text = ArabicText::digitsToAscii(trim((string) $value));

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : $text;
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $text;
        }

        return $text;
    }

    /** نعم / لا (any spelling), yes / no, 1 / 0, true / false; null when it is none of them. */
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return in_array((int) $value, [0, 1], true) ? (bool) $value : null;
        }

        return match (ArabicText::searchKey((string) $value)) {
            'نعم', 'yes', 'true', '1', 'صح', '✔' => true,
            'لا', 'no', 'false', '0', 'خطا', '✘' => false,
            default => null,
        };
    }
}
