<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__proxy-probe', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'url' => url('/x'),
        ]);
    }

    public function test_a_private_proxy_passes_the_https_scheme_and_client_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-For' => '198.51.100.7, 172.18.0.1',
                'X-Forwarded-Host' => 'karmandeman.ir',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('/__proxy-probe')
            ->assertOk()
            ->assertJson(['secure' => true, 'ip' => '198.51.100.7', 'url' => 'https://karmandeman.ir/x']);
    }

    public function test_a_public_peer_cannot_claim_https_or_another_address(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-For' => '198.51.100.7',
                'X-Forwarded-Host' => 'evil.example',
            ])
            ->getJson('/__proxy-probe')
            ->assertOk()
            ->assertJson(['secure' => false, 'ip' => '203.0.113.9'])
            ->assertJsonMissing(['url' => 'https://evil.example/x']);
    }
}
