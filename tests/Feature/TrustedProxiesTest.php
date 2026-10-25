<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/test-address', fn () => request()->ip());
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function trust(?string $proxies): void
    {
        config(['app.trusted_proxies' => $proxies]);
        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    private function addressSeen(string $proxy): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/test-address')
            ->getContent();
    }

    public function test_without_trusted_proxies_the_forwarded_address_is_ignored(): void
    {
        $this->trust(null);

        $this->assertSame('10.0.0.2', $this->addressSeen('10.0.0.2'));
    }

    public function test_a_listed_proxy_passes_the_visitors_address_on(): void
    {
        $this->trust('127.0.0.1, 10.0.0.0/8');

        $this->assertSame('203.0.113.7', $this->addressSeen('10.0.0.2'));
        $this->assertSame('192.0.2.50', $this->addressSeen('192.0.2.50'));
    }

    public function test_a_star_trusts_any_proxy(): void
    {
        $this->trust('*');

        $this->assertSame('203.0.113.7', $this->addressSeen('192.0.2.50'));
    }
}
