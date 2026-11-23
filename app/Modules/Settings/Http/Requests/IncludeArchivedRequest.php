<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IncludeArchivedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Also the archived items or units (to show old values). */
            'include_archived' => ['nullable', 'boolean'],
        ];
    }
}
