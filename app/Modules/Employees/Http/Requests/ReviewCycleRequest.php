<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Confirm a cycle for the current period: the changes found among the cycle's fields, or none. */
class ReviewCycleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cycle' => ['required', 'in:monthly,quarterly,semi_annual,annual'],
            'values' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return (array) $this->input('values', []);
    }
}
