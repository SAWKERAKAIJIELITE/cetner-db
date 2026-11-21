<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Part of the username, a name or the personal number. */
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,slug'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
