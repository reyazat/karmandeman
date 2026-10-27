<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Data\Crypto\DataKey;

/** Key-encryption keys per workspace, held outside the application database (ADR-013). */
interface WorkspaceKeyManager
{
    /** Idempotently creates the non-exportable key. */
    public function ensureKey(string $keyName): void;

    public function createDataKey(string $keyName): DataKey;

    /** Raw data-key bytes; throws when the key is missing or soft-deleted. */
    public function unwrapDataKey(string $keyName, string $wrapped): string;

    /** Reversible containment: every use of the key fails until restored. */
    public function softDelete(string $keyName): void;

    /** Irreversible in the live cluster; snapshots taken before still hold the key. */
    public function destroy(string $keyName): void;
}
