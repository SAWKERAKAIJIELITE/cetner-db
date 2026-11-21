<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffUser
 */
class StaffUserResource extends JsonResource
{
    /** Include the effective permissions (the detail and `me`, not the list). */
    public bool $withPermissions = false;

    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'is_active' => (bool) $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $this->deactivation_reason,
            'role' => $this->role === null ? null : [
                'slug' => $this->role->slug,
                'label' => $this->role->label_ar,
            ],
            'role_assigned_at' => $this->role_assigned_at?->toIso8601String(),
            'permissions' => $this->when($this->withPermissions, fn () => $this->permissionSlugs()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
