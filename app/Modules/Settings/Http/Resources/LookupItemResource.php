<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\ListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListItem
 */
class LookupItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            /** Set for a system item (renamed, never archived); null otherwise. */
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'archived' => $this->isArchived(),
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
