<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** «لوحة التحكم»: «جهة العمل» only (no period, OD-26). */
class OverviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** «جهة العمل» */
            'org_unit_id' => ['nullable', 'integer'],
            /** With the units under it (default true). */
            'include_sub_units' => ['nullable', 'boolean'],
            /** How many of the most often missing fields (default 5). */
            'missing_limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }
}
