<?php

namespace Tests\Support;

use App\Modules\Employees\Actions\SubmitEmployeeChanges;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use App\Modules\Settings\Models\ListItem;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait StaffTestHelpers
{
    protected const PASSWORD = 'Password1!';

    protected const API = '/api/v1/staff';

    private static int $staffCounter = 0;

    private static int $macCounter = 0;

    protected function staffUser(StaffRole $role = StaffRole::DataEntry, array $attributes = []): StaffUser
    {
        $n = ++self::$staffCounter;
        $letters = substr(str_repeat(chr(97 + $n % 26), 2).chr(97 + intdiv($n, 26) % 26), 0, 3);

        $user = new StaffUser(array_merge([
            'username' => 'user.'.$letters.'.'.str_repeat('x', $n % 5),
            'first_name' => 'موظف',
            'last_name' => 'تجريبي',
            'personal_number' => (string) (200000 + $n),
            'password' => self::PASSWORD,
        ], array_intersect_key($attributes, array_flip(['username', 'first_name', 'last_name', 'personal_number', 'password']))));

        $user->forceFill([
            'role_id' => Role::query()->where('slug', $role->value)->value('id'),
            'role_assigned_at' => now(),
            'is_active' => true,
        ])->save();

        if (($attributes['is_active'] ?? true) === false) {
            $user->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivation_reason' => 'اختبار'])->save();
        }

        return $user->refresh();
    }

    protected function admin(): StaffUser
    {
        return $this->staffUser(StaffRole::SystemAdmin);
    }

    protected function devicePayload(int $key = 0, array $overrides = []): array
    {
        $mac = sprintf('00:1a:2b:3c:%02x:%02x', intdiv(++self::$macCounter, 256) % 256, self::$macCounter % 256);

        return array_merge([
            'label' => 'حاسوب الإدخال '.$key,
            'public_key' => TestDeviceKeys::publicKey($key),
            'reported_mac' => $mac,
            'os_info' => 'Windows 11 Pro 23H2',
        ], $overrides);
    }

    protected function pendingDevice(int $key = 0): StaffDevice
    {
        $pem = DeviceCrypto::canonicalPublicKey(TestDeviceKeys::publicKey($key));

        $device = new StaffDevice(array_merge($this->devicePayload($key), [
            'public_key' => $pem,
            'fingerprint' => DeviceCrypto::fingerprint($pem),
        ]));
        $device->save();

        return $device->refresh();
    }

    protected function approvedDevice(StaffUser $owner, int $key = 0): StaffDevice
    {
        $device = $this->pendingDevice($key);
        $device->forceFill(['status' => DeviceStatus::Approved, 'staff_user_id' => $owner->id, 'approved_at' => now()])->save();

        return $device->refresh();
    }

    /** POST /auth/challenge then /auth/login, signed with the given key. */
    protected function loginThrough(StaffDevice $device, string $username, int $key = 0, string $password = self::PASSWORD): TestResponse
    {
        $challenge = $this->postJson(self::API.'/auth/challenge', ['device_id' => $device->id])->assertCreated()->json();
        $payload = DeviceCrypto::loginPayload($challenge['challenge_id'], $challenge['challenge'], $device->id, $username);

        return $this->postJson(self::API.'/auth/login', [
            'device_id' => $device->id,
            'challenge_id' => $challenge['challenge_id'],
            'signature' => TestDeviceKeys::sign($payload, $key),
            'username' => $username,
            'password' => $password,
        ]);
    }

    protected function actingAsStaff(StaffUser $user): static
    {
        Sanctum::actingAs($user, ['*'], 'staff');

        return $this;
    }

    /** A list item's id by its label (or code). */
    protected function itemId(string $list, string $labelOrCode): int
    {
        return (int) ListItem::queryFor($list)->where(fn ($q) => $q->where('label', $labelOrCode)->orWhere('code', $labelOrCode))->value('id');
    }

    private static int $nationalCounter = 0;

    /** An approved (active) file, added by an administrator (applied at once). */
    protected function employeeFile(array $values = []): Employee
    {
        $set = app(SubmitEmployeeChanges::class)->create(array_merge([
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'family_name' => 'الخطيب',
            'national_number' => (string) (10000000000 + ++self::$nationalCounter),
        ], $values), null, $this->admin());

        return Employee::query()->findOrFail($set->employee_id);
    }
}
