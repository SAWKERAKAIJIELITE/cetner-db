<?php

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Reports\Support\MovementsByEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of «ملخص جميع الموظفين»: the file and its figures for the period.
 *
 * @mixin Employee
 */
class MovementsEmployeeResource extends JsonResource
{
    /** Set for each page by the controller: one lookup of the labels for the page. */
    public static ?ValueLabels $labels = null;

    public function toArray(Request $request): array
    {
        self::$labels ??= new ValueLabels;
        [$rating, $ratingLabel] = MovementsByEmployee::rating((float) $this->getAttribute('net'));

        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'full_name' => $this->fullName(),
            'job_title' => self::$labels->ref('job_title_id', $this->job_title_id),
            'job_status' => self::$labels->ref('job_status_id', $this->job_status_id),
            'internal_leaves' => (int) $this->getAttribute('internal_leaves'),
            'external_leaves' => (int) $this->getAttribute('external_leaves'),
            /** Every leave type's days in the period. */
            'leave_days' => (int) $this->getAttribute('leave_days'),
            /** «رصيد الإجازات (السنوي)»: the year's allowance less the year's internal and external leave, whatever the month. */
            'leave_balance' => (int) $this->getAttribute('leave_balance'),
            'rewards' => (int) $this->getAttribute('rewards'),
            'rewards_amount' => number_format((float) $this->getAttribute('rewards_amount'), 2, '.', ''),
            'deductions' => (int) $this->getAttribute('deductions'),
            'deductions_amount' => number_format((float) $this->getAttribute('deductions_amount'), 2, '.', ''),
            'transfers' => (int) $this->getAttribute('transfers'),
            /** Rewards less deductions. */
            'net' => number_format((float) $this->getAttribute('net'), 2, '.', ''),
            'movements' => (int) $this->getAttribute('movements'),
            'planned_days' => (float) $this->getAttribute('planned_days'),
            'attended_days' => (float) $this->getAttribute('attended_days'),
            /** 0 to 100 */
            'absence_rate' => (float) $this->getAttribute('absence_rate'),
            'warnings' => (int) $this->getAttribute('warnings'),
            /** «تقييم»: positive (net above 0), negative, neutral */
            'rating' => $rating,
            'rating_label' => $ratingLabel,
        ];
    }
}
