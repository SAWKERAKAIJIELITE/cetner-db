<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Support\DocumentSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the 13 kinds on the file: the current file (null while none) and the uploads waiting for review.
 *
 * @mixin DocumentSlot
 */
class DocumentSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => new DocumentKindResource($this->kind),
            'current' => $this->current ? new DocumentResource($this->current) : null,
            'pending' => DocumentResource::collection($this->pending),
        ];
    }
}
