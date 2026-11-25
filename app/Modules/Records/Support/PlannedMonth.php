<?php

namespace App\Modules\Records\Support;

/** A month's planned working days (D-043): what PlannedDays computes. */
final readonly class PlannedMonth
{
    /**
     * @param  list<int>  $weeklyDaysOff  ISO weekdays
     * @param  list<string>  $holidayDates  the holiday dates on working days, each once
     */
    public function __construct(
        public int $year,
        public int $month,
        public int $daysInMonth,
        public array $weeklyDaysOff,
        public int $workingDays,
        public array $holidayDates,
        public int $plannedDays,
    ) {}
}
