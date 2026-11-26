<?php

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Support\DocumentCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A document for the file (multipart/form-data). */
class UploadDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** One of GET /document-kinds: the 13 attachments, or `conduct_document` with `conduct_record_id`. */
            'kind' => ['required', 'string', Rule::in(DocumentCatalogue::kinds())],
            /** PDF, JPEG, PNG or WebP (read from the content), at most 5 MB. */
            'file' => ['required', 'file', 'max:'.DocumentCatalogue::maxKilobytes()],
            /** The conduct record («سجل السلوك الوظيفي») the document is for: only with `conduct_document`. */
            'conduct_record_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
