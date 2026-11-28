<?php

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Support\MovementsByEmployee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** «ملخص جميع الموظفين»: the period, and the employees list's search and unit. */
class MovementsEmployeesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            /** A name (any spelling), or the start of a personal or national number (any digits). */
            'q' => ['nullable', 'string', 'max:100'],
            'org_unit_id' => ['nullable', 'integer'],
            'include_sub_units' => ['nullable', 'boolean'],
            /** name (default), personal_number, net, movements, leave_days, absence_rate, warnings */
            'sort' => ['nullable', Rule::in(MovementsByEmployee::SORTS)],
            /** asc or desc; the name and the number default to asc, the figures to desc. */
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
