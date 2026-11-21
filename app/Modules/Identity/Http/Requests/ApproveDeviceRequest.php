<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The fingerprint read on the employee's screen, typed back (spaces and case ignored). */
            'fingerprint' => ['required', 'string', 'max:200'],
            /** The one staff user who owns the device. */
            'staff_user_id' => ['required', 'uuid', 'exists:staff_users,id'],
        ];
    }
}
