<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddRolePermissionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'distinct', 'exists:permissions,slug'],
        ];
    }
}
