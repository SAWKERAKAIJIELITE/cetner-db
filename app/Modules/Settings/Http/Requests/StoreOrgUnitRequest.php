<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrgUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'not_regex:/^\s*$/u'],
            /** The unit it belongs to; absent for a top-level unit. */
            'parent_id' => ['nullable', 'integer', 'exists:org_units,id'],
        ];
    }
}
