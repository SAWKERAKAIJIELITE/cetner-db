<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Support\ManagedList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManagedList
 */
class LookupListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            /** The fields that use it. */
            'used_by' => $this->usedBy,
            'items' => LookupItemResource::collection($this->items ?? collect()),
        ];
    }
}
