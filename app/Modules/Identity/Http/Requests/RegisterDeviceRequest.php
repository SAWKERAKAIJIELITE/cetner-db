<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** A short name for the computer, shown to the administrator. */
            'label' => ['required', 'string', 'max:150'],
            /** The ECDSA P-256 public key, SPKI PEM. */
            'public_key' => ['required', 'string', 'max:5000'],
            /** Informational: shown to the administrator, not a credential. */
            'reported_mac' => ['required', 'mac_address'],
            'os_info' => ['required', 'string', 'max:255'],
        ];
    }
}
