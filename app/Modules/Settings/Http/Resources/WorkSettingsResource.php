<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\WorkSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkSettings
 */
class WorkSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'branch_name' => $this->branch_name,
            'daily_hours' => (float) $this->daily_hours,
            /** HH:MM */
            'starts_at' => substr((string) $this->starts_at, 0, 5),
            'ends_at' => substr((string) $this->ends_at, 0, 5),
            'late_after_minutes' => $this->late_after_minutes,
            'work_days_per_week' => $this->work_days_per_week,
            /** ISO weekdays: 1 Monday … 5 Friday, 6 Saturday, 7 Sunday. */
            'weekly_days_off' => array_map('intval', $this->weekly_days_off),
            'currency' => $this->currency,
            'annual_leave_days' => $this->annual_leave_days,
            /** Days a review of each cycle stays valid. */
            'review_windows' => [
                'monthly' => $this->review_window_monthly,
                'quarterly' => $this->review_window_quarterly,
                'semi_annual' => $this->review_window_semi_annual,
                'annual' => $this->review_window_annual,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
