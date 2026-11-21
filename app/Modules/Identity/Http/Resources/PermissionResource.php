<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label_ar,
            /** It only shows data: the general manager's role takes only these. */
            'read_only' => (bool) $this->read_only,
        ];
    }
}
