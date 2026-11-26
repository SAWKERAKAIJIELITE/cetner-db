<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverdueReviewsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'org_unit_id' => ['nullable', 'integer'],
            'include_sub_units' => ['nullable', 'boolean'],
            /** One cycle only: monthly, quarterly, semi_annual, annual; any of them by default. */
            'cycle' => ['nullable', 'in:monthly,quarterly,semi_annual,annual'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
