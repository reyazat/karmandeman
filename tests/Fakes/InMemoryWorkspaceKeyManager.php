<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Data\Crypto\DataKey;
use App\Services\Crypto\WorkspaceKeyManager;
use RuntimeException;

/** Test double with the same contract semantics as OpenBao Transit; not a security boundary. */
final class InMemoryWorkspaceKeyManager implements WorkspaceKeyManager
{
    /** @var array<string, array{key: string, soft_deleted: bool}> */
    public array $keys = [];

    public int $unwrapCalls = 0;

    /** Simulates an unreachable key service during purge. */
    public bool $failDestroy = false;

    /** @var array<string, true> */
    private array $destroyed = [];

    public function ensureKey(string $keyName): void
    {
        $this->keys[$keyName] ??= ['key' => $this->derive($keyName), 'soft_deleted' => false];
        unset($this->destroyed[$keyName]);
    }

    public function createDataKey(string $keyName): DataKey
    {
        $plaintext = random_bytes(32);
        $nonce = random_bytes(12);
        $tag = '';
        $wrapped = openssl_encrypt($plaintext, 'aes-256-gcm', $this->usable($keyName), OPENSSL_RAW_DATA, $nonce, $tag);

        return new DataKey($plaintext, 'fake:v1:'.base64_encode($nonce.$tag.$wrapped));
    }

    public function unwrapDataKey(string $keyName, string $wrapped): string
    {
        $this->unwrapCalls++;
        $raw = base64_decode(substr($wrapped, strlen('fake:v1:')), true);
        $plaintext = openssl_decrypt(substr((string) $raw, 28), 'aes-256-gcm', $this->usable($keyName), OPENSSL_RAW_DATA, substr((string) $raw, 0, 12), substr((string) $raw, 12, 16));
        if ($plaintext === false) {
            throw new RuntimeException('OpenBao rejected the request (400).');
        }

        return $plaintext;
    }

    public function exists(string $keyName): bool
    {
        return isset($this->keys[$keyName]);
    }

    public function softDelete(string $keyName): void
    {
        $this->keys[$keyName]['soft_deleted'] = true;
    }

    public function destroy(string $keyName): bool
    {
        if ($this->failDestroy) {
            throw new RuntimeException('OpenBao request failed.');
        }
        $existed = isset($this->keys[$keyName]);
        unset($this->keys[$keyName]);
        $this->destroyed[$keyName] = true;

        return $existed;
    }

    private function usable(string $keyName): string
    {
        if (isset($this->destroyed[$keyName]) || ($this->keys[$keyName]['soft_deleted'] ?? false)) {
            throw new RuntimeException('OpenBao rejected the request (400).');
        }

        // Like a shared key store: a key made by a forked concurrency worker is known here too.
        return $this->keys[$keyName]['key'] ?? $this->derive($keyName);
    }

    private function derive(string $keyName): string
    {
        return hash_hmac('sha256', $keyName, 'km-test-only-kek', true);
    }
}
