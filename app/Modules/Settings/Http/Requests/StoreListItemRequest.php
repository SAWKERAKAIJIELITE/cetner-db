<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreListItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:150', 'not_regex:/^\s*$/u'],
            /** Where it shows; the end of the list when absent. */
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
        ];
    }
}
