<?php

namespace App\Modules\Imports\Http\Requests;

use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Enums\RowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportRowsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** employees, movements, attendance, conduct, debts; all by default */
            'sheet' => ['nullable', Rule::enum(ImportSheet::class)],
            /** valid, review, invalid, excluded; all by default */
            'status' => ['nullable', Rule::enum(RowStatus::class)],
            /** Rows of this «الرقم الذاتي». */
            'personal_number' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
