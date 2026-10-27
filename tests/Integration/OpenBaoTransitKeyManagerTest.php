<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Crypto\OpenBaoTransitKeyManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Runs only against a disposable OpenBao (OPENBAO_TEST_ADDR / OPENBAO_TEST_TOKEN). */
final class OpenBaoTransitKeyManagerTest extends TestCase
{
    private OpenBaoTransitKeyManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $addr = getenv('OPENBAO_TEST_ADDR') ?: null;
        $token = getenv('OPENBAO_TEST_TOKEN') ?: null;
        if ($addr === null || $token === null) {
            $this->markTestSkipped('Requires a disposable OpenBao: set OPENBAO_TEST_ADDR and OPENBAO_TEST_TOKEN.');
        }
        Http::allowStrayRequests(); // Real disposable OpenBao only; TestCase blocks other outbound HTTP.
        config(['services.openbao' => ['addr' => $addr, 'token' => $token, 'transit_mount' => 'transit', 'key_prefix' => 'ws-', 'timeout' => 5]]);
        Http::withHeaders(['X-Vault-Token' => $token])->post(rtrim($addr, '/').'/v1/sys/mounts/transit', ['type' => 'transit']);
        $this->manager = new OpenBaoTransitKeyManager;
    }

    private function keyName(): string
    {
        return 'ws-test-'.Str::uuid();
    }

    public function test_data_key_round_trip_and_idempotent_key_creation(): void
    {
        $name = $this->keyName();
        $this->manager->ensureKey($name);
        $this->manager->ensureKey($name);
        $key = $this->manager->createDataKey($name);

        $this->assertSame(32, strlen($key->plaintext));
        $this->assertStringStartsWith('vault:v1:', $key->wrapped);
        $this->assertSame($key->plaintext, $this->manager->unwrapDataKey($name, $key->wrapped));
    }

    public function test_soft_delete_blocks_use_and_destroy_is_final_for_that_key_only(): void
    {
        $name = $this->keyName();
        $other = $this->keyName();
        $this->manager->ensureKey($name);
        $this->manager->ensureKey($other);
        $key = $this->manager->createDataKey($name);
        $kept = $this->manager->createDataKey($other);

        $this->manager->softDelete($name);
        $this->assertUnwrapFails($name, $key->wrapped);
        $this->manager->destroy($name);
        $this->assertUnwrapFails($name, $key->wrapped);
        $this->assertUnwrapFails($other, $key->wrapped);

        $this->assertSame($kept->plaintext, $this->manager->unwrapDataKey($other, $kept->wrapped));
    }

    public function test_failures_do_not_leak_the_token(): void
    {
        config(['services.openbao.token' => 'not-a-valid-token']);
        try {
            $this->manager->ensureKey($this->keyName());
            $this->fail('Invalid token was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('not-a-valid-token', $exception->getMessage());
        }
    }

    private function assertUnwrapFails(string $name, string $wrapped): void
    {
        try {
            $this->manager->unwrapDataKey($name, $wrapped);
            $this->fail('Unwrap succeeded with a removed key.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
