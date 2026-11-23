<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->format('Y-m-d'),
            'ends_on' => $this->ends_on->format('Y-m-d'),
            /** Both ends included. */
            'days' => $this->days,
            'notes' => $this->notes,
        ];
    }
}
