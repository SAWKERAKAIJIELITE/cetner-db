<?php

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Adding a holiday (every field) or correcting one (the fields sent, checked with the stored ones). */
class HolidayRequest extends FormRequest
{
    private function isUpdate(): bool
    {
        return $this->route('holiday') instanceof Holiday;
    }

    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150', 'not_regex:/^\s*$/u'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['starts_on', 'ends_on'])) {
                return;
            }

            $holiday = $this->route('holiday');
            $starts = $this->input('starts_on', $holiday instanceof Holiday ? $holiday->starts_on?->format('Y-m-d') : null);
            $ends = $this->input('ends_on', $holiday instanceof Holiday ? $holiday->ends_on?->format('Y-m-d') : null);

            if ($starts === null || $ends === null) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('Y-m-d', $starts)->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', $ends)->startOfDay();

            if ($to->lessThan($from)) {
                $validator->errors()->add('ends_on', __('settings.holiday_ends_before_start'));
            } elseif ($from->diffInDays($to) >= 366) {
                $validator->errors()->add('ends_on', __('settings.holiday_too_long'));
            }
        }];
    }
}
