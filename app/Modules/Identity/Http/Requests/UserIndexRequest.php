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
            /** username (default), created_at, last_login_at */
            'sort' => ['nullable', 'in:username,created_at,last_login_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
