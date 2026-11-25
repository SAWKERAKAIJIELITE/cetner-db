<?php

namespace App\Modules\Records\Http\Resources;

use App\Modules\Records\Support\PlannedMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A month's planned working days (D-043).
 *
 * @mixin PlannedMonth
 */
class PlannedDaysResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'days_in_month' => $this->daysInMonth,
            /** ISO weekdays. */
            'weekly_days_off' => $this->weeklyDaysOff,
            'working_days' => $this->workingDays,
            /** The holiday dates on working days, each once. */
            'holiday_dates' => $this->holidayDates,
            /** The suggestion: working days less those holidays. */
            'planned_days' => $this->plannedDays,
        ];
    }
}
