<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest as BaseRequest;

class FormRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            /** «سنة التقارير» of «تقرير الحالة الوظيفية»; this year in Damascus by default. Ignored by the two other forms. */
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
