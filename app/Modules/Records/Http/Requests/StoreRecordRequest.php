<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A record to add: whose, and its values (field => value, as `GET /records/{type}/fields` lists them). */
class StoreRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Whose record; or personal_number. A payment may give neither: its debt's employee. */
            'employee_id' => ['nullable', 'uuid', 'required_without_all:personal_number,values.debt_id'],
            /** «الرقم الذاتي», instead of employee_id; an unknown number is refused. */
            'personal_number' => ['nullable', 'string', 'max:20'],
            /** field => value; the required fields of the kind must be there. */
            'values' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
