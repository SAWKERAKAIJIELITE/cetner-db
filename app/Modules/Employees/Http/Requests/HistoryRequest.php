<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Support\FieldCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HistoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** One field's history. */
            'field' => ['nullable', Rule::in(FieldCatalogue::keys())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,200'],
        ];
    }
}
