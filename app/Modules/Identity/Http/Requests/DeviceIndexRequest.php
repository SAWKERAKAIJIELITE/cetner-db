<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
