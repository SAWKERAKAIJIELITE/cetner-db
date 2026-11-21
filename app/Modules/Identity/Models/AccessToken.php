<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's token, with the device it was issued to (D-013): revoking the device deletes its tokens.
 *
 * @property ?string $staff_device_id
 */
class AccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public function device(): BelongsTo
    {
        return $this->belongsTo(StaffDevice::class, 'staff_device_id');
    }
}
