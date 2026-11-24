<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Approving (a note is optional) or refusing (a reason is required). */
class DecisionRequest extends FormRequest
{
    public function rules(): array
    {
        $refusing = str_ends_with($this->path(), '/reject');

        return [
            'note' => $refusing ? ['required', 'string', 'min:3', 'max:1000'] : ['nullable', 'string', 'max:1000'],
        ];
    }
}
