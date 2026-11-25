<?php

namespace App\Modules\Records\Support;

use App\Modules\Settings\Models\Holiday;
use App\Modules\Settings\Models\WorkSettings;
use Carbon\CarbonImmutable;

/**
 * A month's planned working days, suggested to the attendance entry (D-043): the month's days, less the weekly
 * days off, less the official holidays falling on a working day, each date counted once even where holidays
 * overlap (D-027). A suggestion: the entry may differ (a unit working on a holiday).
 */
final class PlannedDays
{
    /** @return array<string, mixed> */
    public static function of(int $year, int $month): array
    {
        $first = CarbonImmutable::create($year, $month, 1);
        $last = $first->endOfMonth()->startOfDay();
        $daysOff = array_map('intval', WorkSettings::current()->weekly_days_off);

        $holidayDates = [];

        foreach (Holiday::query()->where('starts_on', '<=', $last->format('Y-m-d'))->where('ends_on', '>=', $first->format('Y-m-d'))->get() as $holiday) {
            $day = CarbonImmutable::parse($holiday->starts_on->format('Y-m-d'))->max($first);
            $end = CarbonImmutable::parse($holiday->ends_on->format('Y-m-d'))->min($last);

            for (; $day <= $end; $day = $day->addDay()) {
                if (! in_array($day->isoWeekday(), $daysOff, true)) {
                    $holidayDates[$day->format('Y-m-d')] = true;
                }
            }
        }

        $working = 0;

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            if (! in_array($day->isoWeekday(), $daysOff, true)) {
                $working++;
            }
        }

        ksort($holidayDates);

        return [
            'year' => $year,
            'month' => $month,
            'days_in_month' => $first->daysInMonth,
            'weekly_days_off' => $daysOff,
            'working_days' => $working,
            /** The holiday dates on working days, each once. */
            'holiday_dates' => array_keys($holidayDates),
            'planned_days' => $working - count($holidayDates),
        ];
    }
}
