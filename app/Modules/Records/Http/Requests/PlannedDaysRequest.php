<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlannedDaysRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
