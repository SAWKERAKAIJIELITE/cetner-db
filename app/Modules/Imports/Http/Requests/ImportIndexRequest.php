<?php

namespace App\Modules\Imports\Http\Requests;

use App\Modules\Imports\Enums\ImportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** pending, applying, approved, failed, rejected, cancelled; all by default */
            'status' => ['nullable', Rule::enum(ImportStatus::class)],
            /** Only the ones I uploaded. */
            'mine' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
