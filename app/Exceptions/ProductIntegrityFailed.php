<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** An approved revision no longer matches its MACs; it is never exported (ADR-019). */
final class ProductIntegrityFailed extends RuntimeException
{
    public function __construct(public readonly string $productId, public readonly string $reason)
    {
        parent::__construct("Product integrity check failed: {$reason}.");
    }
}
