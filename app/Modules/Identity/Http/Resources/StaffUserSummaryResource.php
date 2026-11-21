<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who did something: enough to name them.
 *
 * @mixin StaffUser
 */
class StaffUserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'username' => $this->username,
            'full_name' => $this->fullName(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
