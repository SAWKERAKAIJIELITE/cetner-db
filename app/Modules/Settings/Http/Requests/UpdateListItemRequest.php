<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required_without:sort_order', 'string', 'max:150', 'not_regex:/^\s*$/u'],
            'sort_order' => ['required_without:label', 'integer', 'between:0,100000'],
        ];
    }
}
