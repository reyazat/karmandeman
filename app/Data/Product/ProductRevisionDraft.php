<?php

declare(strict_types=1);

namespace App\Data\Product;

/**
 * A normalized revision ready to store: the encrypted payload, its MAC, the
 * SKU uniqueness MAC and the review flags counted in SQL (ADR-016, ADR-017).
 */
final readonly class ProductRevisionDraft
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $payloadMac,
        public ?string $skuMac,
        public bool $needsConfirmation,
        public bool $priceMissing,
        public bool $imageMissing,
    ) {}
}
