<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'challenge_id' => ['required', 'uuid'],
            /** base64 of the DER-encoded ECDSA-P256-SHA256 signature of the payload. */
            'signature' => ['required', 'string', 'max:512'],
            /** As signed: trimmed, its case unchanged. */
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
