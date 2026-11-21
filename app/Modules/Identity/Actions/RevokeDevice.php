<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A lost or retired computer: revoked with a reason, its tokens deleted at once (D-013). */
final class RevokeDevice
{
    public function handle(StaffDevice $device, string $reason, StaffUser $actor): StaffDevice
    {
        return DB::transaction(function () use ($device, $reason, $actor) {
            $device = StaffDevice::query()->lockForUpdate()->findOrFail($device->getKey());

            if ($device->status === DeviceStatus::Revoked) {
                throw ValidationException::withMessages(['device' => __('identity.device_already_revoked')]);
            }

            $device->forceFill([
                'status' => DeviceStatus::Revoked,
                'revoked_by' => $actor->getKey(),
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            $tokens = $device->tokens()->delete();

            AuditTrail::record('device.revoked', $actor, [
                'device_id' => $device->getKey(),
                'staff_user_id' => $device->staff_user_id,
                'reason' => $reason,
                'tokens_deleted' => $tokens,
            ]);

            return $device;
        });
    }
}
