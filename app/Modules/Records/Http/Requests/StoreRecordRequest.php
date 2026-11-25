<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A record to add: whose, and its values (field => value, as `GET /records/{type}/fields` lists them). */
class StoreRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            /** field => value; the required fields of the kind must be there. */
            'values' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
