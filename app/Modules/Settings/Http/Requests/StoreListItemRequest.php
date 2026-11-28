<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreListItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The item's Arabic name, as the hiring system's lists. */
            'name_ar' => ['required', 'string', 'max:150', 'not_regex:/^\s*$/u'],
            /** Where it shows; the end of the list when absent. */
            'sort_order' => ['nullable', 'integer', 'between:0,100000'],
        ];
    }
}
