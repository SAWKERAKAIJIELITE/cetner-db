<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\StaffPassword;
use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:200', 'confirmed', StaffPassword::rule()],
        ];
    }
}
