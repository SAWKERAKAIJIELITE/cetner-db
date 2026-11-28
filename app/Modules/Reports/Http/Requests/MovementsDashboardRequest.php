<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** «داش بورد الحركات»: a year, a month if any, an employee if any. */
class MovementsDashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            /** Empty: the whole year. */
            'month' => ['nullable', 'integer', 'between:1,12'],
            /** «مؤشرات الموظف المحدد»: an approved file. */
            'employee_id' => ['nullable', 'uuid', Rule::exists('employees', 'id')->where('file_status', 'active')],
        ];
    }
}
