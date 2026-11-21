<?php

namespace App\Modules\Identity\Support;

/**
 * Staff usernames are Latin only: 3 to 50 of a-z . _ - (D-008). Stored and compared trimmed and
 * lowercase; the database CHECK guards every other code path.
 */
final class Username
{
    public const PATTERN = '/^[a-z._-]{3,50}$/';

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, self::normalize($value)) === 1;
    }
}
