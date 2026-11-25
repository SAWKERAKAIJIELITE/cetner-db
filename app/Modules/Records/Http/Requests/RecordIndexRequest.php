<?php

namespace App\Modules\Records\Http\Requests;

use App\Modules\Records\Enums\DebtStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'uuid'],
            /** The employee's الرقم الذاتي, as the workbook's rows were found. */
            'personal_number' => ['nullable', 'string', 'max:20'],
            /** The year of the record's date (of the month, for the attendance; of the debt's date, for the debts). */
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            /** The movement, conduct or debt type: an item of its list. */
            'type_id' => ['nullable', 'integer', 'min:1'],
            /** Debts only: paid, overdue, outstanding. */
            'debt_status' => ['nullable', Rule::enum(DebtStatus::class)],
            /** Attendance only: months at or above the high absence rate (20%). */
            'high_absence' => ['nullable', 'boolean'],
            /** Removed records instead of the kept ones. */
            'deleted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
