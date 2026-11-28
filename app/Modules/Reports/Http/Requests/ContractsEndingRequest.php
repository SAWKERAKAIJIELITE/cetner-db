<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContractsEndingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'org_unit_id' => ['nullable', 'integer'],
            'include_sub_units' => ['nullable', 'boolean'],
            /** Ending from today to this many days ahead (default 30). */
            'days' => ['nullable', 'integer', 'between:0,365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
