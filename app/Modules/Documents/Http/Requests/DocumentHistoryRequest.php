<?php

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Support\DocumentCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentHistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** One kind's uploads only. */
            'kind' => ['nullable', 'string', Rule::in(DocumentCatalogue::kinds())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
