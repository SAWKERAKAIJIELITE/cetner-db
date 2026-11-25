<?php

namespace App\Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A notification in the hiring system's shape (D-046).
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{kind?: string, title?: string, message?: string, details?: array<string, mixed>} $data */
        $data = $this->data;

        return [
            'id' => (string) $this->id,
            /** change_rejected, contract_ending_soon */
            'kind' => (string) ($data['kind'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            /** @var array<string, string|int|float|null> what the screen links from: ids, names, dates */
            'details' => (array) ($data['details'] ?? []),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
