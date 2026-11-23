<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** Every active item's id, in the new order. */
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
