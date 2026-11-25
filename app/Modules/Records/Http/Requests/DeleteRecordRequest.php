<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Removing a record entered by mistake: why. */
class DeleteRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Why: 3 to 2,000 characters. */
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
