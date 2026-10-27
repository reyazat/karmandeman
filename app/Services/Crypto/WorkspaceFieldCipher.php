<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceDataKeyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Envelope encryption of private fields with the workspace's own data key
 * (AES-256-GCM, ADR-013). The AAD binds each value to its workspace and field,
 * so ciphertext moved to another row, column or tenant fails to decrypt.
 * Unwrapped keys are held in memory for one request or job only.
 */
final class WorkspaceFieldCipher
{
    private const PREFIX = 'kmv1:';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    /** @var array<int, string> */
    private array $keys = [];

    public function __construct(
        private readonly WorkspaceKeyManager $keyManager,
        private readonly WorkspaceDataKeyRepositoryInterface $dataKeys,
        private readonly LockActiveWorkspaceAction $lockActive,
    ) {}

    public function encrypt(Workspace $workspace, string $context, #[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $this->dataKey($workspace), OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($workspace, $context), self::TAG_BYTES,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX.base64_encode($nonce.$tag.$ciphertext);
    }

    public function decrypt(Workspace $workspace, string $context, string $value): string
    {
        $raw = str_starts_with($value, self::PREFIX) ? base64_decode(substr($value, strlen(self::PREFIX)), true) : false;
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Encrypted value is malformed.');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES), 'aes-256-gcm', $this->dataKey($workspace, create: false), OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES), substr($raw, self::NONCE_BYTES, self::TAG_BYTES), $this->aad($workspace, $context),
        );
        if ($plaintext === false) {
            throw new RuntimeException('Encrypted value cannot be decrypted.');
        }

        return $plaintext;
    }

    public function keyName(Workspace $workspace): string
    {
        return config('services.openbao.key_prefix').$workspace->public_id;
    }

    private function dataKey(Workspace $workspace, bool $create = true): string
    {
        if (isset($this->keys[$workspace->id])) {
            return $this->keys[$workspace->id];
        }
        $stored = $this->dataKeys->forWorkspace($workspace);
        if ($stored !== null) {
            return $this->keys[$workspace->id] = $this->keyManager->unwrapDataKey($stored->transit_key, $stored->wrapped_key);
        }
        if (! $create) {
            throw new RuntimeException('Workspace has no data key.');
        }

        // Only an active workspace gets a new key; the row lock serializes creation.
        return $this->keys[$workspace->id] = DB::transaction(function () use ($workspace): string {
            $this->lockActive->execute($workspace);
            $stored = $this->dataKeys->forWorkspace($workspace);
            if ($stored !== null) {
                return $this->keyManager->unwrapDataKey($stored->transit_key, $stored->wrapped_key);
            }
            $name = $this->keyName($workspace);
            $this->keyManager->ensureKey($name);
            $key = $this->keyManager->createDataKey($name);
            $this->dataKeys->create($workspace, $name, $key->wrapped);

            return $key->plaintext;
        });
    }

    private function aad(Workspace $workspace, string $context): string
    {
        return "km:{$workspace->public_id}:{$context}";
    }
}
