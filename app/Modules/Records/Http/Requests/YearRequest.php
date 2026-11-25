<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class YearRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The report's year («سنة التقارير»); this year in Damascus by default. */
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
