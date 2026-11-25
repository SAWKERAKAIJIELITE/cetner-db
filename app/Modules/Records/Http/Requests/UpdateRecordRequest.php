<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The fields to correct, field => new value; null empties a field that may be empty. */
class UpdateRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
