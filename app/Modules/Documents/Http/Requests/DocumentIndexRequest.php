<?php

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Employees\Enums\ChangeSetStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** pending (default: the review queue), approved, rejected, withdrawn */
            'status' => ['nullable', Rule::enum(ChangeSetStatus::class)],
            'kind' => ['nullable', 'string', Rule::in(DocumentCatalogue::kinds())],
            'employee_id' => ['nullable', 'uuid'],
            /** Only the ones I uploaded. */
            'mine' => ['nullable', 'boolean'],
            /** uploaded_at (the queue's default, oldest first) or reviewed_at (decided uploads' default, newest first). */
            'sort' => ['nullable', 'in:uploaded_at,reviewed_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
