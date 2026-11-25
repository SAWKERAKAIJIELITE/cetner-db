<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Removing a record entered by mistake: why. */
class DeleteRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
