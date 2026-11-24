<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A new employee or changes: `values` keyed by field (see GET /field-definitions), and a note for the reviewer. */
class SubmitValuesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** field => value; a list field takes the item's id, a unit field the unit's id; null empties a field. */
            'values' => ['required', 'array', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return (array) $this->input('values', []);
    }
}
