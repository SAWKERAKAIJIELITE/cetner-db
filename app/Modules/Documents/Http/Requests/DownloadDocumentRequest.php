<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** 1: shown in the browser (a preview) instead of saved. */
            'inline' => ['nullable', 'boolean'],
        ];
    }
}
