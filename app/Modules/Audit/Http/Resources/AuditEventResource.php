<?php

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Support\AuditEvents;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEvent
 */
class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => AuditEvents::label($this->event),
            'category' => $this->category,
            'actor' => $this->actor ? new StaffUserSummaryResource($this->actor) : null,
            'context' => (object) $this->context,
            'ip' => $this->ip,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
