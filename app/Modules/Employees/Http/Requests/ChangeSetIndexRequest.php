<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSetIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** pending (default), approved, rejected, withdrawn */
            'status' => ['nullable', Rule::enum(ChangeSetStatus::class)],
            'kind' => ['nullable', Rule::enum(ChangeSetKind::class)],
            'employee_id' => ['nullable', 'uuid'],
            /** Only the ones I submitted. */
            'mine' => ['nullable', 'boolean'],
            /** Only those that change who the person is (the administrator's to approve). */
            'needs_identity_review' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
