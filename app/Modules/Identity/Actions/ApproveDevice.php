<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator approves a pending device for its one owner after comparing the fingerprint shown on
 * the employee's screen (D-013). The console approval (bootstrap, break-glass) has no approver.
 */
final class ApproveDevice
{
    public function handle(StaffDevice $device, string $typedFingerprint, StaffUser $owner, ?StaffUser $approver, string $via = 'api'): StaffDevice
    {
        return DB::transaction(function () use ($device, $typedFingerprint, $owner, $approver, $via) {
            // The owner first, then the device: every approval locks in this order.
            $owner = StaffUser::query()->lockForUpdate()->findOrFail($owner->getKey());
            $device = StaffDevice::query()->lockForUpdate()->findOrFail($device->getKey());

            if ($device->status !== DeviceStatus::Pending) {
                throw ValidationException::withMessages(['device' => __('identity.device_not_pending')]);
            }

            if (! hash_equals($device->fingerprint, DeviceCrypto::normalizeFingerprint($typedFingerprint))) {
                throw ValidationException::withMessages(['fingerprint' => __('identity.device_fingerprint_mismatch')]);
            }

            if (! $owner->is_active) {
                throw ValidationException::withMessages(['staff_user_id' => __('identity.device_owner_inactive')]);
            }

            $hasDevice = StaffDevice::query()
                ->where('staff_user_id', $owner->getKey())
                ->where('status', DeviceStatus::Approved)
                ->exists();

            if ($hasDevice) {
                throw ValidationException::withMessages(['staff_user_id' => __('identity.device_owner_has_device')]);
            }

            $device->forceFill([
                'status' => DeviceStatus::Approved,
                'staff_user_id' => $owner->getKey(),
                'approved_by' => $approver?->getKey(),
                'approved_at' => now(),
            ])->save();

            AuditTrail::record('device.approved', $approver, [
                'device_id' => $device->getKey(),
                'staff_user_id' => $owner->getKey(),
                'label' => $device->label,
                'via' => $via,
            ]);

            return $device;
        });
    }
}
