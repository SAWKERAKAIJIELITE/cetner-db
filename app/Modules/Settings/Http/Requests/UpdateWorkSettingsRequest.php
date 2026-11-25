<?php

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Models\WorkSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The fields sent change; the rules between fields are checked with the stored values. */
class UpdateWorkSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_name' => ['sometimes', 'string', 'max:150', 'not_regex:/^\s*$/u'],
            'daily_hours' => ['sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'max:24'],
            /** HH:MM */
            'starts_at' => ['sometimes', 'date_format:H:i'],
            /** HH:MM, after starts_at */
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'late_after_minutes' => ['sometimes', 'integer', 'between:0,240'],
            /** Sent with weekly_days_off, which must list 7 − this many days. */
            'work_days_per_week' => ['sometimes', 'integer', 'between:1,7'],
            /** The weekly days off, ISO weekdays: 1 Monday … 5 Friday, 6 Saturday, 7 Sunday. */
            'weekly_days_off' => ['sometimes', 'array', 'max:6'],
            'weekly_days_off.*' => ['integer', 'between:1,7', 'distinct'],
            'currency' => ['sometimes', 'string', 'max:20', 'not_regex:/^\s*$/u'],
            'annual_leave_days' => ['sometimes', 'integer', 'between:0,365'],
            /** Days a monthly review stays valid; then quarterly ≥ monthly, semi-annual ≥ quarterly, annual ≥ semi-annual. */
            'review_window_monthly' => ['sometimes', 'integer', 'between:1,1000'],
            'review_window_quarterly' => ['sometimes', 'integer', 'between:1,1000'],
            'review_window_semi_annual' => ['sometimes', 'integer', 'between:1,1000'],
            'review_window_annual' => ['sometimes', 'integer', 'between:1,1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = WorkSettings::current();
            $value = fn (string $field) => $this->input($field, $current->getAttribute($field));

            if (substr((string) $value('ends_at'), 0, 5) <= substr((string) $value('starts_at'), 0, 5)) {
                $validator->errors()->add('ends_at', __('settings.work_ends_before_start'));
            }

            if ($this->has('work_days_per_week') || $this->has('weekly_days_off')) {
                $off = array_values(array_unique(array_map('intval', (array) $value('weekly_days_off'))));

                if (count($off) !== 7 - (int) $value('work_days_per_week')) {
                    $validator->errors()->add('weekly_days_off', __('settings.days_off_count', ['count' => 7 - (int) $value('work_days_per_week')]));
                }
            }

            $windows = ['review_window_monthly', 'review_window_quarterly', 'review_window_semi_annual', 'review_window_annual'];

            for ($i = 1; $i < count($windows); $i++) {
                if ((int) $value($windows[$i]) < (int) $value($windows[$i - 1])) {
                    $validator->errors()->add($windows[$i], __('settings.review_windows_order'));
                }
            }
        }];
    }
}
