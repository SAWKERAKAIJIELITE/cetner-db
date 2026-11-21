<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use App\Modules\Identity\Support\LoginResult;
use App\Modules\Identity\Support\Username;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The signed login (D-013, D-014): challenge consumed, device approved, signature valid, then the user,
 * the password, the active flag and the device's owner. Every failure gives the same message; the reason
 * goes to the log only.
 */
final class LoginStaff
{
    /** Compared against when the user does not exist, so the time taken does not tell. */
    private const DUMMY_HASH = '$2y$12$SWnsqA2tssjTsH.Z8Ab6f.MxgiH0oMo4f3eHVOsF/0rHz42LQNdUy';

    public function handle(string $deviceId, string $challengeId, string $signature, string $username, string $password): LoginResult
    {
        // 1. Consume the challenge atomically: valid, unexpired, unused, bound to this device.
        // The application's clock, like the challenge's expiry when it was issued.
        $now = now();
        $consumed = DB::selectOne(
            'UPDATE device_login_challenges SET used_at = ?
             WHERE id = ? AND staff_device_id = ? AND used_at IS NULL AND expires_at > ?
             RETURNING challenge',
            [$now, $challengeId, $deviceId, $now],
        );

        if ($consumed === null) {
            $this->fail('challenge_invalid');
        }

        // 2. The device is approved and signed the payload built from stored values and the username as sent.
        $device = StaffDevice::query()->find($deviceId);

        if ($device === null || ! $device->isApproved()) {
            $this->fail('device_not_approved');
        }

        $payload = DeviceCrypto::loginPayload($challengeId, $consumed->challenge, $deviceId, $username);

        if (! DeviceCrypto::verify($device->public_key, $payload, $signature)) {
            $this->fail('bad_signature');
        }

        // 3. Only then the user, with equal work whether or not they exist.
        $user = StaffUser::query()->where('username', Username::normalize($username))->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);
            $this->fail('unknown_user');
        }

        // 4. Password, active, owner of this device.
        if (! Hash::check($password, $user->password)) {
            $this->fail('wrong_password');
        }

        if (! $user->is_active) {
            $this->fail('inactive_user');
        }

        if ($device->staff_user_id !== $user->getKey()) {
            $this->fail('device_of_another_user');
        }

        // 5. A token bound to the device, until the business day ends (00:00 in Damascus, D-009).
        return DB::transaction(function () use ($user, $device) {
            $expiresAt = BusinessDay::startOfNext();

            $device->forceFill(['last_seen_at' => now()])->save();
            $user->forceFill(['last_login_at' => now()])->save();

            $newToken = $user->createToken('staff:'.$device->label, ['*'], $expiresAt);
            $newToken->accessToken->forceFill(['staff_device_id' => $device->getKey()])->save();

            AuditTrail::record('auth.login', $user, ['device_id' => $device->getKey()]);

            return new LoginResult($newToken->plainTextToken, $expiresAt, $user);
        });
    }

    private function fail(string $reason): never
    {
        Log::warning('staff_login_failed', ['reason' => $reason]);

        throw ValidationException::withMessages(['username' => __('auth.failed')]);
    }
}
