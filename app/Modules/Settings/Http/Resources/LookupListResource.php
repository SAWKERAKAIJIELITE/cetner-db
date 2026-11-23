<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\LookupList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LookupList
 */
class LookupListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label_ar,
            /** The fields that use it. */
            'used_by' => $this->description_ar,
            'items' => LookupItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
