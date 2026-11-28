<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** D-054: the user's address behind a reverse proxy, as the throttles and the audit log read it. */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => $request->ip());
    }

    private function ipSeen(string $proxy): string
    {
        return (string) $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeader('X-Forwarded-For', '203.0.113.9')
            ->get('/_test/ip')->assertOk()->getContent();
    }

    public function test_without_a_trusted_proxy_the_forwarded_header_is_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('172.18.0.2', $this->ipSeen('172.18.0.2'));
    }

    public function test_a_trusted_proxy_passes_on_the_users_address(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->assertSame('203.0.113.9', $this->ipSeen('172.18.0.2'));
    }

    public function test_a_caller_outside_the_trusted_proxies_cannot_choose_its_address(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->assertSame('198.51.100.7', $this->ipSeen('198.51.100.7'));
    }
}
