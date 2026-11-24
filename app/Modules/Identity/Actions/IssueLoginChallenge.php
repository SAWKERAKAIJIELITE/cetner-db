<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\DeviceLoginChallenge;
use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** A random, single-use challenge bound to one approved device, short lived (D-013). */
final class IssueLoginChallenge
{
    public function handle(string $deviceId): DeviceLoginChallenge
    {
        $device = StaffDevice::query()->find($deviceId);

        if ($device === null || ! $device->isApproved()) {
            Log::warning('staff_login_failed', [
                'reason' => $device === null ? 'challenge_unknown_device' : 'challenge_device_not_approved',
                'device_id' => $deviceId,
                'ip' => request()->ip(),
            ]);

            throw ValidationException::withMessages(['device_id' => __('auth.failed')]);
        }

        return DeviceLoginChallenge::query()->create([
            'staff_device_id' => $device->getKey(),
            'challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'expires_at' => now()->addSeconds((int) config('staff_auth.challenge_ttl_seconds')),
        ]);
    }
}
