<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label_ar,
            /** Holds every permission, always; not edited. */
            'administrator' => $this->isAdministrator(),
            /** Takes read-only permissions only. */
            'read_only' => $this->isReadOnly(),
            'permissions' => $this->permissionSlugs(),
            'users_count' => (int) ($this->users_count ?? 0),
        ];
    }
}
