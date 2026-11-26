<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameOrgUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The unit's Arabic name. */
            'name_ar' => ['required', 'string', 'max:150', 'not_regex:/^\s*$/u'],
        ];
    }
}
