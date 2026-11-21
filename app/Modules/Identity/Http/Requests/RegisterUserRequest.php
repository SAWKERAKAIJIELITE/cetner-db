<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\StaffPassword;
use App\Modules\Identity\Support\Username;
use App\Support\ArabicText;
use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'username' => is_string($this->input('username')) ? Username::normalize($this->input('username')) : null,
            'personal_number' => is_string($this->input('personal_number')) ? ArabicText::digitsToAscii(trim($this->input('personal_number'))) : null,
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        return [
            /** Latin letters, dot, underscore and hyphen; 3 to 50. Stored lowercase. */
            'username' => ['required', 'string', 'regex:'.Username::PATTERN, 'unique:staff_users,username'],
            'first_name' => ['required', 'string', 'min:3', 'max:100', 'not_regex:/[0-9٠-٩]/u'],
            'last_name' => ['required', 'string', 'min:3', 'max:100', 'not_regex:/[0-9٠-٩]/u'],
            /** Digits only, 4 to 50 (Arabic digits accepted). */
            'personal_number' => ['required', 'string', 'regex:/^[0-9]{4,50}$/', 'unique:staff_users,personal_number'],
            'is_active' => ['sometimes', 'boolean'],
            /** The role's slug. */
            'role' => ['required', 'string', 'exists:roles,slug'],
            'password' => ['required', 'string', 'max:200', 'confirmed', StaffPassword::rule()],
        ];
    }
}
