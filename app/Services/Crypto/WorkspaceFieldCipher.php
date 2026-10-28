<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Exceptions\UndecryptableValue;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceDataKeyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Envelope encryption of private fields with the workspace's own data key
 * (AES-256-GCM, ADR-013). The AAD binds each value to its workspace and
 * table.column, so ciphertext moved to another column or tenant fails to
 * decrypt. It does not bind the row: a swap between two rows of the same
 * workspace and column still decrypts (recorded limitation, ADR-014).
 * Unwrapped keys are held in memory for one request or job only.
 */
final class WorkspaceFieldCipher
{
    private const PREFIX = 'kmv1:';

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    /** @var array<int, array{wrapped: string, key: string, committed: bool}> */
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
            throw new UndecryptableValue('Encrypted value is malformed.');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES), 'aes-256-gcm', $this->dataKey($workspace, create: false), OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES), substr($raw, self::NONCE_BYTES, self::TAG_BYTES), $this->aad($workspace, $context),
        );
        if ($plaintext === false) {
            throw new UndecryptableValue('Encrypted value cannot be decrypted.');
        }

        return $plaintext;
    }

    /**
     * Keyed HMAC for equality lookups (e.g. duplicate detection) under the
     * workspace's data key, so the digest dies with the key (ADR-013).
     */
    public function mac(Workspace $workspace, string $context, #[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, hash_hkdf('sha256', $this->dataKey($workspace), 32, "km-mac:{$context}"));
    }

    public function keyName(Workspace $workspace): string
    {
        return config('services.openbao.key_prefix').$workspace->public_id;
    }

    private function dataKey(Workspace $workspace, bool $create = true): string
    {
        $cached = $this->keys[$workspace->id] ?? null;
        if ($cached !== null && $cached['committed']) {
            return $cached['key'];
        }
        // A key first seen inside an open transaction may vanish on rollback;
        // re-check its row so nothing is encrypted under a key stored nowhere.
        $stored = $this->dataKeys->forWorkspace($workspace);
        if ($stored !== null) {
            if ($cached === null || $cached['wrapped'] !== $stored->wrapped_key) {
                $cached = ['wrapped' => $stored->wrapped_key, 'key' => $this->keyManager->unwrapDataKey($stored->transit_key, $stored->wrapped_key)];
            }

            return $this->remember($workspace, $cached['wrapped'], $cached['key']);
        }
        unset($this->keys[$workspace->id]);
        if (! $create) {
            throw new RuntimeException('Workspace has no data key.');
        }

        // Only an active workspace gets a new key; the row lock serializes creation.
        [$wrapped, $key] = DB::transaction(function () use ($workspace): array {
            $this->lockActive->execute($workspace);
            $stored = $this->dataKeys->forWorkspace($workspace);
            if ($stored !== null) {
                return [$stored->wrapped_key, $this->keyManager->unwrapDataKey($stored->transit_key, $stored->wrapped_key)];
            }
            $name = $this->keyName($workspace);
            $this->keyManager->ensureKey($name);
            $key = $this->keyManager->createDataKey($name);
            $this->dataKeys->create($workspace, $name, $key->wrapped);

            return [$key->wrapped, $key->plaintext];
        });

        return $this->remember($workspace, $wrapped, $key);
    }

    private function remember(Workspace $workspace, string $wrapped, #[\SensitiveParameter] string $key): string
    {
        $this->keys[$workspace->id] = ['wrapped' => $wrapped, 'key' => $key, 'committed' => DB::transactionLevel() === 0];

        return $key;
    }

    private function aad(Workspace $workspace, string $context): string
    {
        return "km:{$workspace->public_id}:{$context}";
    }
}
