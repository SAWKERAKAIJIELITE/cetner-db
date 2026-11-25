<?php

namespace App\Modules\Audit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuditEventIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** An event (`access.user_created`), or a kind ending with a dot (`access.`). */
            'event' => ['nullable', 'string', 'max:100', 'regex:/^[a-z_]+(\.[a-z_]*)*$/'],
            'actor_id' => ['nullable', 'uuid'],
            /** A record the event is about: an id in its context. */
            'related_id' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:security,operations'],
            /** Damascus days, both included. */
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,200'],
        ];
    }
}
