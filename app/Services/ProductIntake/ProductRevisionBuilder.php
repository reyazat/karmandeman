<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Data\Product\ProductRevisionDraft;
use App\Models\Workspace;
use App\Services\Crypto\WorkspaceFieldCipher;

/**
 * Turns a validated draft into a storable revision. Every MAC uses the
 * workspace key, so none outlives key destruction as a guessable digest.
 */
final readonly class ProductRevisionBuilder
{
    public function __construct(
        private ProductDraftNormalizer $normalizer,
        private PersianTextNormalizer $text,
        private WorkspaceFieldCipher $cipher,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function build(Workspace $workspace, array $draft): ProductRevisionDraft
    {
        $payload = $this->normalizer->normalize($draft);
        $sku = $payload['sku']['value'];
        $name = $payload['name']['value'];
        $nameKey = $name === null ? '' : $this->text->nameKey($name);
        $flags = $this->normalizer->reviewFlags($payload);

        return new ProductRevisionDraft(
            payload: $payload,
            payloadMac: $this->cipher->mac($workspace, 'product_revisions.payload', json_encode($payload, JSON_THROW_ON_ERROR)),
            skuMac: $sku === null ? null : $this->cipher->mac($workspace, 'products.sku', $this->text->skuKey($sku)),
            needsConfirmation: $flags['needs_confirmation'],
            priceMissing: $flags['price_missing'],
            imageMissing: $flags['image_missing'],
            nameMac: $nameKey === '' ? null : $this->cipher->mac($workspace, 'products.name', $nameKey),
        );
    }
}
