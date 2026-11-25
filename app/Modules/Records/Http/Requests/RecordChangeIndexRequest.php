<?php

namespace App\Modules\Records\Http\Requests;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Records\Enums\RecordType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordChangeIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** pending (default), approved, rejected, withdrawn */
            'status' => ['nullable', Rule::enum(ChangeSetStatus::class)],
            'record_type' => ['nullable', Rule::enum(RecordType::class)],
            /** Submissions with a change for this employee. */
            'employee_id' => ['nullable', 'uuid'],
            /** Only the ones I submitted. */
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
