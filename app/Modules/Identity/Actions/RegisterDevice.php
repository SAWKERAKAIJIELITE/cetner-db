<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A device asks to be trusted: a pending record, idempotent by public key (D-013). */
final class RegisterDevice
{
    /**
     * @param  array{label: string, public_key: string, reported_mac: string, os_info: string}  $data
     * @return array{0: StaffDevice, 1: bool} the device, and whether it was created now
     */
    public function handle(array $data): array
    {
        $pem = DeviceCrypto::canonicalPublicKey($data['public_key']);

        if ($pem === null) {
            throw ValidationException::withMessages(['public_key' => __('identity.device_key_invalid')]);
        }

        $fingerprint = DeviceCrypto::fingerprint($pem);

        return DB::transaction(function () use ($data, $pem, $fingerprint) {
            // Serialises registrations, so the pending cap and the unique fingerprint cannot be raced.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['staff_devices.register']);

            $existing = StaffDevice::query()->where('fingerprint', $fingerprint)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $pending = StaffDevice::query()->where('status', DeviceStatus::Pending)->count();

            if ($pending >= (int) config('staff_auth.max_pending_devices')) {
                throw ValidationException::withMessages(['public_key' => __('identity.device_pending_limit')]);
            }

            $device = StaffDevice::query()->create([
                'label' => $data['label'],
                'public_key' => $pem,
                'fingerprint' => $fingerprint,
                'reported_mac' => $data['reported_mac'],
                'os_info' => $data['os_info'],
            ]);

            return [$device->refresh(), true];
        });
    }
}
