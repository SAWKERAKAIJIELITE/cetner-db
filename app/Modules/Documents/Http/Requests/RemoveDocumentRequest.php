<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Taking a current document off the file: why. */
class RemoveDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Why: 3 to 2,000 characters. */
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
