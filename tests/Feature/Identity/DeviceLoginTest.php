<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\DeviceLoginChallenge;
use App\Modules\Identity\Support\DeviceCrypto;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Tests\Support\TestDeviceKeys;
use Tests\TestCase;

class DeviceLoginTest extends TestCase
{
    public function test_a_signed_login_returns_a_token_until_the_next_midnight_in_damascus(): void
    {
        Carbon::setTestNow('2026-11-21 20:30:00'); // 23:30 in Damascus
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);

        $response = $this->loginThrough($device, $user->username)->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', '2026-11-21T21:00:00+00:00')
            ->assertJsonPath('user.username', $user->username)
            ->assertJsonPath('user.role.slug', 'data_entry');

        $this->assertContains('employees.manage', $response->json('user.permissions'));

        $token = AccessToken::query()->sole();
        $this->assertSame($device->id, $token->staff_device_id);
        $this->assertNotNull($device->refresh()->last_seen_at);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.login', 'actor_id' => $user->id, 'category' => 'security']);

        $this->withToken($response->json('token'))->getJson(self::API.'/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_the_token_stops_at_the_end_of_the_business_day(): void
    {
        Carbon::setTestNow('2026-11-21 08:00:00');
        $user = $this->staffUser();
        $token = $this->loginThrough($this->approvedDevice($user), $user->username)->json('token');

        Carbon::setTestNow(BusinessDay::startOfNext(Carbon::parse('2026-11-21 08:00:00'))->addSecond());
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(self::API.'/auth/me')->assertUnauthorized();
    }

    public function test_the_username_is_signed_as_sent_and_looked_up_lowercase(): void
    {
        $user = $this->staffUser(attributes: ['username' => 'entry.one']);

        $this->loginThrough($this->approvedDevice($user), 'Entry.One')->assertOk()->assertJsonPath('user.username', 'entry.one');
    }

    public function test_a_challenge_is_used_once(): void
    {
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);
        $challenge = $this->postJson(self::API.'/auth/challenge', ['device_id' => $device->id])->json();
        $body = [
            'device_id' => $device->id,
            'challenge_id' => $challenge['challenge_id'],
            'signature' => TestDeviceKeys::sign(DeviceCrypto::loginPayload($challenge['challenge_id'], $challenge['challenge'], $device->id, $user->username)),
            'username' => $user->username,
            'password' => self::PASSWORD,
        ];

        $this->postJson(self::API.'/auth/login', $body)->assertOk();
        $this->postJson(self::API.'/auth/login', $body)->assertUnprocessable()->assertJsonPath('errors.username.0', __('auth.failed'));
    }

    public function test_an_expired_challenge_is_refused(): void
    {
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);
        $challenge = $this->postJson(self::API.'/auth/challenge', ['device_id' => $device->id])->json();
        DeviceLoginChallenge::query()->update(['expires_at' => now()->subSecond()]);

        $this->postJson(self::API.'/auth/login', [
            'device_id' => $device->id,
            'challenge_id' => $challenge['challenge_id'],
            'signature' => TestDeviceKeys::sign(DeviceCrypto::loginPayload($challenge['challenge_id'], $challenge['challenge'], $device->id, $user->username)),
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertUnprocessable();
    }

    public function test_every_failure_gives_the_same_message(): void
    {
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);
        $other = $this->staffUser();
        $this->approvedDevice($other, 1);
        $inactive = $this->staffUser(attributes: ['is_active' => false]);

        $failures = [
            'signed with another key' => fn () => $this->loginThrough($device, $user->username, key: 1),
            'wrong password' => fn () => $this->loginThrough($device, $user->username, password: 'Wrong1!pass'),
            'unknown user' => fn () => $this->loginThrough($device, 'nobody.here'),
            'device of another user' => fn () => $this->loginThrough($device, $other->username),
            'inactive user' => fn () => $this->loginThrough($device, $inactive->username),
        ];

        foreach ($failures as $case => $attempt) {
            $attempt()->assertUnprocessable()->assertExactJson([
                'message' => __('auth.failed'),
                'errors' => ['username' => [__('auth.failed')]],
            ]);
        }

        $this->assertSame(0, AccessToken::query()->count());
    }

    public function test_a_challenge_is_given_to_approved_devices_only(): void
    {
        $pending = $this->pendingDevice();
        $revoked = $this->approvedDevice($this->staffUser(), 1);
        $revoked->forceFill(['status' => DeviceStatus::Revoked, 'revoked_at' => now()])->save();

        $this->postJson(self::API.'/auth/challenge', ['device_id' => $pending->id])->assertUnprocessable();
        $this->postJson(self::API.'/auth/challenge', ['device_id' => $revoked->id])->assertUnprocessable();
        $this->postJson(self::API.'/auth/challenge', ['device_id' => '01890000-0000-7000-8000-000000000000'])->assertUnprocessable();
    }

    public function test_logout_ends_this_session_only(): void
    {
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);
        $first = $this->loginThrough($device, $user->username)->json('token');
        $second = $this->loginThrough($device, $user->username)->json('token');

        $this->withToken($first)->postJson(self::API.'/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($first)->getJson(self::API.'/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson(self::API.'/auth/me')->assertOk();
        $this->assertDatabaseHas('audit_events', ['event' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_a_deactivated_users_token_stops_at_once(): void
    {
        $user = $this->staffUser();
        $token = $this->loginThrough($this->approvedDevice($user), $user->username)->json('token');
        $user->forceFill(['is_active' => false, 'deactivated_at' => now(), 'deactivation_reason' => 'نقل'])->save();

        $this->withToken($token)->getJson(self::API.'/auth/me')->assertUnauthorized()
            ->assertExactJson(['message' => __('auth.unauthenticated')]);
    }

    public function test_the_general_manager_and_the_administrator_log_in_the_same_way(): void
    {
        foreach ([StaffRole::GeneralManager, StaffRole::SystemAdmin] as $i => $role) {
            $user = $this->staffUser($role);
            $this->loginThrough($this->approvedDevice($user, $i), $user->username, $i)->assertOk()->assertJsonPath('user.role.slug', $role->value);
        }
    }
}
