<?php

namespace Tests\Feature;

use App\Services\Crypto\OpenBaoTransitKeyManager;
use Closure;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class OpenBaoClientTlsTest extends TestCase
{
    /** @var array<int, mixed> */
    private array $verify = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::globalMiddleware(fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
            $this->verify[] = $options['verify'] ?? null;

            return $handler($request, $options);
        });
        Http::fake(['bao.test/*' => Http::response(['data' => ['name' => 'ws-a']])]);
        config(['services.openbao' => [
            'addr' => 'https://bao.test',
            'token' => 'test-token',
            'transit_mount' => 'transit',
            'key_prefix' => 'ws-',
            'timeout' => 5,
        ]]);
    }

    public function test_a_configured_ca_file_verifies_the_openbao_channel(): void
    {
        config(['services.openbao.ca_cert' => '/run/km/openbao-ca.crt']);

        $this->assertTrue((new OpenBaoTransitKeyManager)->exists('ws-a'));

        $this->assertSame(['/run/km/openbao-ca.crt'], $this->verify);
    }

    public function test_without_a_ca_file_the_system_bundle_still_verifies(): void
    {
        config(['services.openbao.ca_cert' => '']);

        $this->assertTrue((new OpenBaoTransitKeyManager)->exists('ws-a'));

        // Guzzle's default (true) stays: verification is never switched off.
        $this->assertNotContains(false, $this->verify);
        $this->assertNotContains('/run/km/openbao-ca.crt', $this->verify);
    }
}
