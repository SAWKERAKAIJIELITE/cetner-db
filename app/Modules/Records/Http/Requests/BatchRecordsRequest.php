<?php

namespace App\Modules\Records\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Many records of one kind in one submission (a unit's month of attendance): reviewed and approved together.
 * Removals are sent one by one, each with its reason.
 */
class BatchRecordsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:'.config('hr.records_batch_limit')],
            /** create or update */
            'changes.*.action' => ['required', 'in:create,update'],
            /** For create: whose record. */
            'changes.*.employee_id' => ['required_if:changes.*.action,create', 'nullable', 'uuid'],
            /** For update: which record. */
            'changes.*.record_id' => ['required_if:changes.*.action,update', 'nullable', 'uuid'],
            'changes.*.values' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
