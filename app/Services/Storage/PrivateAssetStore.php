<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\UndecryptableValue;
use App\Models\Asset;
use App\Models\Workspace;
use App\Services\Crypto\WorkspaceFieldCipher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Private object storage for batch input and output files (PRD FR-025, ADR-022, ADR-052). Every
 * object is AES-256-GCM ciphertext under the workspace data key, bound to the
 * asset's public id, under a per-workspace prefix that erasure deletes whole.
 * Keys are random: no file name, user or content is visible in the store.
 */
final readonly class PrivateAssetStore
{
    public const KIND_PHOTO = 'photo';

    public const KIND_TABLE = 'table';

    public const KIND_VOICE = 'voice';

    /** A generated output file, such as the catalogue PDF (ADR-052). */
    public const KIND_ARTIFACT = 'artifact';

    public function __construct(private WorkspaceFieldCipher $cipher) {}

    /**
     * Writes the encrypted object and returns its storage key. A product
     * table (ADR-040) is bound to its own context, so it never decrypts as a
     * photo or the other way round.
     */
    public function put(Workspace $workspace, string $assetPublicId, #[\SensitiveParameter] string $bytes, string $kind = self::KIND_PHOTO): string
    {
        $key = $this->prefix($workspace).Str::uuid()->toString();
        if (! $this->disk()->put($key, $this->cipher->encryptBytes($workspace, $this->context($assetPublicId, $kind), $bytes))) {
            throw new RuntimeException('Asset object could not be written.');
        }

        return $key;
    }

    /**
     * Plain bytes. A missing, moved, swapped or edited object throws
     * UndecryptableValue; a storage outage throws its own error.
     */
    public function read(Workspace $workspace, Asset $asset): string
    {
        return $this->readObject($workspace, $asset->storage_key, $asset->public_id, self::KIND_PHOTO);
    }

    /** Plain bytes of any object of the workspace, checked against its id and kind. */
    public function readObject(Workspace $workspace, string $storageKey, string $publicId, string $kind): string
    {
        $disk = $this->disk();
        if (! str_starts_with($storageKey, $this->prefix($workspace)) || ! $disk->exists($storageKey)) {
            throw new UndecryptableValue('Asset object is missing.');
        }

        // A disk without 'throw' answers null on failure: an outage, not tampering.
        $stored = $disk->get($storageKey) ?? throw new RuntimeException('Asset object could not be read.');

        return $this->cipher->decryptBytes($workspace, $this->context($publicId, $kind), $stored);
    }

    public function delete(string $storageKey): void
    {
        $this->disk()->delete($storageKey);
    }

    /** Erasure (FR-087): every object of the workspace, including orphans of failed uploads. */
    public function deleteWorkspace(Workspace $workspace): void
    {
        if (! $this->disk()->deleteDirectory(rtrim($this->prefix($workspace), '/'))) {
            throw new RuntimeException('Workspace asset objects could not be deleted.');
        }
    }

    public function contentMac(Workspace $workspace, #[\SensitiveParameter] string $bytes, string $kind = self::KIND_PHOTO): string
    {
        return $this->cipher->mac($workspace, match ($kind) {
            self::KIND_PHOTO => 'assets.content',
            self::KIND_TABLE => 'product_tables.content',
            self::KIND_VOICE => 'voice_assets.content',
            self::KIND_ARTIFACT => 'artifacts.content',
            default => throw new \InvalidArgumentException('Unknown private asset kind.'),
        }, hash('sha256', $bytes, true));
    }

    private function prefix(Workspace $workspace): string
    {
        return "ws/{$workspace->public_id}/";
    }

    private function context(string $publicId, string $kind): string
    {
        return match ($kind) {
            self::KIND_PHOTO => "assets.original:{$publicId}",
            self::KIND_TABLE => "product_tables.original:{$publicId}",
            self::KIND_VOICE => "voice_assets.original:{$publicId}",
            self::KIND_ARTIFACT => "artifacts.file:{$publicId}",
            default => throw new \InvalidArgumentException('Unknown private asset kind.'),
        };
    }

    /** Production fails closed unless the files go to the private S3-compatible bucket. */
    private function disk(): Filesystem
    {
        $name = (string) config('karmandeman.assets.disk');
        if (app()->isProduction() && config("filesystems.disks.{$name}.driver") !== 's3') {
            throw new RuntimeException('Asset storage must be the private S3-compatible bucket in production.');
        }

        return Storage::disk($name);
    }
}
