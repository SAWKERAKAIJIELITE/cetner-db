<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Approving (a `note` is optional) or refusing (a `reason` is required, as the hiring system's dialog sends it). */
class DecisionRequest extends FormRequest
{
    public function rules(): array
    {
        return str_ends_with($this->path(), '/reject')
            ? [
                /** Why it goes back to its author: 3 to 2,000 characters. */
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
            ]
            : ['note' => ['nullable', 'string', 'max:2000']];
    }

    /** The decision's note: the reason of a refusal, the optional note of an approval. */
    public function note(): ?string
    {
        return $this->validated('reason') ?? $this->validated('note');
    }
}
