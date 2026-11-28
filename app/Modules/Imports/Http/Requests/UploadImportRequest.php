<?php

namespace App\Modules\Imports\Http\Requests;

use App\Modules\Imports\Enums\Duplicates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A workbook to import (multipart/form-data). */
class UploadImportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The owner's workbook (.xlsx, without macros): «قاعدة بيانات الموظفين» and the four registers, each if present. */
            'file' => ['required', 'file', 'max:'.(int) config('hr.import_max_kb')],
            /** «معالجة تكرار الرقم الذاتي»: skip (default: a stored employee or month stays as it is) or update (it takes the file's values). */
            'duplicates' => ['nullable', Rule::enum(Duplicates::class)],
        ];
    }
}
