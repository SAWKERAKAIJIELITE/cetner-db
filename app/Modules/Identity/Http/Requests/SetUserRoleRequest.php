<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetUserRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The role's slug: data_entry, supervisor, general_manager or system_admin. */
            'role' => ['required', 'string', 'exists:roles,slug'],
        ];
    }
}
