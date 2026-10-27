<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Data\Crypto\DataKey;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * OpenBao Transit adapter. Behaviour verified against OpenBao 2.4.1 (ADR-013):
 * keys are non-exportable by default and deletion needs deletion_allowed.
 */
final readonly class OpenBaoTransitKeyManager implements WorkspaceKeyManager
{
    public function ensureKey(string $keyName): void
    {
        $this->send('post', "keys/{$keyName}", ['type' => 'aes256-gcm96', 'exportable' => false, 'allow_plaintext_backup' => false]);
    }

    public function createDataKey(string $keyName): DataKey
    {
        $data = $this->send('post', "datakey/plaintext/{$keyName}", ['bits' => 256])->json('data');
        $plaintext = base64_decode((string) ($data['plaintext'] ?? ''), true);
        if ($plaintext === false || strlen($plaintext) !== 32 || ! is_string($data['ciphertext'] ?? null)) {
            throw new RuntimeException('OpenBao returned an invalid data key.');
        }

        return new DataKey($plaintext, $data['ciphertext']);
    }

    public function unwrapDataKey(string $keyName, string $wrapped): string
    {
        $plaintext = base64_decode((string) $this->send('post', "decrypt/{$keyName}", ['ciphertext' => $wrapped])->json('data.plaintext'), true);
        if ($plaintext === false || strlen($plaintext) !== 32) {
            throw new RuntimeException('OpenBao returned an invalid data key.');
        }

        return $plaintext;
    }

    public function softDelete(string $keyName): void
    {
        $this->send('delete', "keys/{$keyName}/soft-delete");
    }

    public function destroy(string $keyName): void
    {
        $this->send('post', "keys/{$keyName}/config", ['deletion_allowed' => true]);
        $this->send('delete', "keys/{$keyName}");
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $path, array $payload = []): Response
    {
        $config = config('services.openbao');
        if (empty($config['addr']) || empty($config['token'])) {
            throw new RuntimeException('OpenBao is not configured.');
        }
        try {
            $response = $this->client($config)->{$method}("{$config['transit_mount']}/{$path}", $payload);
        } catch (Throwable) {
            // Never surface the token or payload (which may hold key material).
            throw new RuntimeException('OpenBao request failed.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("OpenBao rejected the request ({$response->status()}).");
        }

        return $response;
    }

    /** @param array<string, mixed> $config */
    private function client(array $config): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $config['addr'], '/').'/v1')
            ->withHeaders(['X-Vault-Token' => (string) $config['token']])
            ->acceptJson()->asJson()
            ->connectTimeout(3)->timeout((int) $config['timeout']);
    }
}
