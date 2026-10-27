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

    public function ensureKey(string $keyName): void
    {
        $this->keys[$keyName] ??= ['key' => random_bytes(32), 'soft_deleted' => false];
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

    public function softDelete(string $keyName): void
    {
        $this->keys[$keyName]['soft_deleted'] = true;
    }

    public function destroy(string $keyName): void
    {
        unset($this->keys[$keyName]);
    }

    private function usable(string $keyName): string
    {
        if (! isset($this->keys[$keyName]) || $this->keys[$keyName]['soft_deleted']) {
            throw new RuntimeException('OpenBao rejected the request (400).');
        }

        return $this->keys[$keyName]['key'];
    }
}
