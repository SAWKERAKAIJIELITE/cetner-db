<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\Support\TestDeviceKeys;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    public function test_registration_is_idempotent_by_public_key(): void
    {
        $first = $this->postJson(self::API.'/devices/register', $this->devicePayload())->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $fingerprint = DeviceCrypto::displayFingerprint(DeviceCrypto::fingerprint(DeviceCrypto::canonicalPublicKey(TestDeviceKeys::publicKey())));
        $first->assertJsonPath('data.fingerprint', $fingerprint);

        $this->postJson(self::API.'/devices/register', $this->devicePayload())->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, StaffDevice::query()->count());
    }

    public function test_registration_checks_the_key_and_the_mac_address(): void
    {
        $this->postJson(self::API.'/devices/register', $this->devicePayload(overrides: ['public_key' => 'not a key']))
            ->assertUnprocessable()->assertJsonPath('errors.public_key.0', __('identity.device_key_invalid'));

        $this->postJson(self::API.'/devices/register', $this->devicePayload(overrides: ['reported_mac' => 'zz:zz']))
            ->assertUnprocessable()->assertJsonValidationErrors('reported_mac');

        $this->postJson(self::API.'/devices/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['label', 'public_key', 'reported_mac', 'os_info']);
    }

    public function test_pending_devices_are_capped(): void
    {
        config(['staff_auth.max_pending_devices' => 1]);
        $this->postJson(self::API.'/devices/register', $this->devicePayload(0))->assertCreated();

        $this->postJson(self::API.'/devices/register', $this->devicePayload(1))->assertUnprocessable()
            ->assertJsonPath('errors.public_key.0', __('identity.device_pending_limit'));
    }

    public function test_the_status_tells_the_waiting_screen_nothing_about_the_owner(): void
    {
        $device = $this->pendingDevice();

        $this->getJson(self::API."/devices/{$device->id}/status")->assertOk()
            ->assertExactJson(['data' => ['id' => $device->id, 'status' => 'pending', 'fingerprint' => DeviceCrypto::displayFingerprint($device->fingerprint)]]);

        $this->getJson(self::API.'/devices/01890000-0000-7000-8000-000000000000/status')->assertNotFound();
        $this->getJson(self::API.'/devices/not-a-uuid/status')->assertNotFound();
    }

    public function test_an_administrator_approves_a_device_after_comparing_the_fingerprint(): void
    {
        $admin = $this->admin();
        $owner = $this->staffUser();
        $device = $this->pendingDevice();

        $this->actingAsStaff($admin)->postJson(self::API."/devices/{$device->id}/approve", [
            'fingerprint' => 'ABCD',
            'staff_user_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonPath('errors.fingerprint.0', __('identity.device_fingerprint_mismatch'));

        $this->postJson(self::API."/devices/{$device->id}/approve", [
            'fingerprint' => strtolower(DeviceCrypto::displayFingerprint($device->fingerprint)),
            'staff_user_id' => $owner->id,
        ])->assertOk()->assertJsonPath('data.status.value', 'approved')->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.approved_by.id', $admin->id);

        $this->assertDatabaseHas('audit_events', ['event' => 'device.approved', 'actor_id' => $admin->id]);

        $this->postJson(self::API."/devices/{$device->id}/approve", [
            'fingerprint' => $device->fingerprint,
            'staff_user_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonPath('errors.device.0', __('identity.device_not_pending'));
    }

    public function test_one_approved_device_per_user_and_none_for_the_inactive(): void
    {
        $this->actingAsStaff($this->admin());
        $owner = $this->staffUser();
        $this->approvedDevice($owner, 0);
        $second = $this->pendingDevice(1);
        $inactive = $this->staffUser(attributes: ['is_active' => false]);

        $this->postJson(self::API."/devices/{$second->id}/approve", ['fingerprint' => $second->fingerprint, 'staff_user_id' => $owner->id])
            ->assertUnprocessable()->assertJsonPath('errors.staff_user_id.0', __('identity.device_owner_has_device'));

        $this->postJson(self::API."/devices/{$second->id}/approve", ['fingerprint' => $second->fingerprint, 'staff_user_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonPath('errors.staff_user_id.0', __('identity.device_owner_inactive'));
    }

    public function test_the_database_keeps_one_approved_device_per_user(): void
    {
        $owner = $this->staffUser();
        $this->approvedDevice($owner, 0);
        $second = $this->pendingDevice(1);

        $this->expectException(QueryException::class);
        $second->forceFill(['status' => DeviceStatus::Approved, 'staff_user_id' => $owner->id, 'approved_at' => now()])->save();
    }

    public function test_revoking_a_device_deletes_its_tokens(): void
    {
        $owner = $this->staffUser();
        $device = $this->approvedDevice($owner);
        $token = $this->loginThrough($device, $owner->username)->json('token');
        $admin = $this->admin();

        $this->actingAsStaff($admin)->postJson(self::API."/devices/{$device->id}/revoke", ['reason' => 'حاسوب مفقود'])
            ->assertOk()->assertJsonPath('data.status.value', 'revoked')->assertJsonPath('data.revoke_reason', 'حاسوب مفقود');

        $this->assertSame(0, AccessToken::query()->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'device.revoked', 'actor_id' => $admin->id]);

        $this->postJson(self::API."/devices/{$device->id}/revoke", ['reason' => 'مرة ثانية'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(self::API.'/auth/me')->assertUnauthorized();
    }

    public function test_the_list_filters_by_status(): void
    {
        $this->pendingDevice(0);
        $this->approvedDevice($this->staffUser(), 1);

        $this->actingAsStaff($this->admin())->getJson(self::API.'/devices?status=pending')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status.value', 'pending');
        $this->getJson(self::API.'/devices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_devices_approve_holders_manage_devices(): void
    {
        $device = $this->pendingDevice();

        foreach ([StaffRole::DataEntry, StaffRole::GeneralManager] as $role) {
            $this->actingAsStaff($this->staffUser($role));
            $this->getJson(self::API.'/devices')->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);
            $this->postJson(self::API."/devices/{$device->id}/approve", ['fingerprint' => $device->fingerprint, 'staff_user_id' => $this->staffUser()->id])->assertForbidden();
        }
    }

    public function test_the_console_approves_the_first_device(): void
    {
        $admin = $this->admin();
        $device = $this->pendingDevice();

        $this->artisan('staff:approve-device', ['device' => $device->id, 'username' => $admin->username, '--fingerprint' => DeviceCrypto::displayFingerprint($device->fingerprint)])
            ->assertSuccessful();

        $device->refresh();
        $this->assertTrue($device->isApproved());
        $this->assertNull($device->approved_by);
        $this->assertDatabaseHas('audit_events', ['event' => 'device.approved', 'actor_id' => null]);

        $other = $this->pendingDevice(1);
        $this->artisan('staff:approve-device', ['device' => $other->id, 'username' => $admin->username, '--fingerprint' => $other->fingerprint])
            ->assertFailed();
    }

    public function test_a_non_fillable_column_is_not_assigned_silently(): void
    {
        $this->expectException(MassAssignmentException::class);

        new StaffDevice(['status' => 'approved']);
    }
}
