<?php

namespace App\Support;

/**
 * Shared Arabic text handling (D-029 of the recruitment system): a search key so that the same name typed
 * different ways finds itself, and digits typed in Arabic turned into ASCII digits.
 * Display values are never altered.
 */
final class ArabicText
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const EXTENDED_ARABIC_INDIC = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public static function digitsToAscii(string $value): string
    {
        $ascii = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace(self::EXTENDED_ARABIC_INDIC, $ascii, str_replace(self::ARABIC_INDIC, $ascii, $value));
    }

    /**
     * No harakat, superscript alef or tatweel; alef forms, ى/ي and ة/ه merged; Arabic digits as ASCII;
     * whitespace collapsed; Latin lowercased.
     */
    public static function searchKey(string $value): string
    {
        $value = self::digitsToAscii($value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $value);
        $value = str_replace('ى', 'ي', $value);
        $value = str_replace('ة', 'ه', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
