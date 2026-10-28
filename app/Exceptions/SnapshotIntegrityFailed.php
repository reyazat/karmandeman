<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A stored snapshot no longer matches its MACs; nothing may be exported from it (ADR-018). */
final class SnapshotIntegrityFailed extends RuntimeException
{
    public function __construct(public readonly string $snapshotId, string $reason)
    {
        parent::__construct("Snapshot integrity check failed: {$reason}.");
    }
}
