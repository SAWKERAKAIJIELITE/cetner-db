<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a device learns about itself: never its owner.
 *
 * @mixin StaffDevice
 */
class DeviceRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** pending, approved or revoked */
            'status' => $this->status->value,
            /** Show it to the employee: the administrator compares it before approving. */
            'fingerprint' => DeviceCrypto::displayFingerprint($this->fingerprint),
        ];
    }
}
