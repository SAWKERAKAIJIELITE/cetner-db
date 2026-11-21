<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequestConventionsTest extends TestCase
{
    public function test_a_request_without_a_valid_token_is_told_in_readable_arabic(): void
    {
        $response = $this->getJson(self::API.'/auth/me')->assertUnauthorized()->assertExactJson(['message' => __('auth.unauthenticated')]);

        $this->assertStringContainsString('سجّل الدخول', $response->getContent());
        $this->assertStringNotContainsString('\u', $response->getContent());
    }

    public function test_a_missing_permission_is_one_arabic_403(): void
    {
        $response = $this->actingAsStaff($this->staffUser())->getJson(self::API.'/users')->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden')]);

        $this->assertStringNotContainsString('\u', $response->getContent());
    }

    public function test_too_many_attempts_is_an_arabic_429_with_retry_after(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(self::API.'/devices/01890000-0000-7000-8000-000000000000/status');
        }

        $response = $this->getJson(self::API.'/devices/01890000-0000-7000-8000-000000000000/status')->assertTooManyRequests()
            ->assertExactJson(['message' => __('auth.throttle_generic')])
            ->assertHeader('Retry-After');

        $this->assertStringNotContainsString('\u', $response->getContent());
    }

    public function test_validation_messages_are_arabic_and_unescaped(): void
    {
        $response = $this->postJson(self::API.'/auth/challenge', [])->assertUnprocessable();

        $this->assertSame('الجهاز مطلوب.', $response->json('errors.device_id.0'));
        $this->assertStringNotContainsString('\u', $response->getContent());
    }

    public function test_an_unknown_api_path_is_an_arabic_404(): void
    {
        $this->getJson('/api/v1/staff/no-such-thing')->assertNotFound()->assertExactJson(['message' => __('auth.not_found')]);
    }

    public function test_the_database_session_is_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
    }
}
