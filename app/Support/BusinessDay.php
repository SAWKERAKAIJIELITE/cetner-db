<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Days of the business: "today", the start of a day and of the next one, in config('hr.timezone')
 * (Damascus). Instants are stored in UTC; only the calendar is local (D-012).
 */
final class BusinessDay
{
    public static function timezone(): string
    {
        return (string) config('hr.timezone', 'Asia/Damascus');
    }

    /** The current business day, at its start, in the business zone. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** The start (00:00 local) of the day after the given instant's business day, as a UTC instant. */
    public static function startOfNext(?DateTimeInterface $at = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone(self::timezone());

        return $local->startOfDay()->addDay()->utc();
    }

    /** The UTC instants [start, end) of a business day given as Y-m-d. */
    public static function bounds(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}
