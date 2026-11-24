<?php

namespace App\Modules\Employees\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFieldDefinitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** The workbook's ✔: the field counts for the file's completeness. */
            'counts_for_completeness' => ['required_without:cycle', 'boolean'],
            'cycle' => ['required_without:counts_for_completeness', 'in:once,monthly,quarterly,semi_annual,annual'],
        ];
    }
}
