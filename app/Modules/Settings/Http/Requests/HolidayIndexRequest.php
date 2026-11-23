<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Holidays touching this year. */
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
