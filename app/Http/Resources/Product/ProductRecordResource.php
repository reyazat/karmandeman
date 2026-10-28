<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use App\Models\ProductRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product record schema 1.0 for one immutable revision (PRD §7.2, ADR-016).
 * Internal API contract, not WooCommerce JSON; no internal ids or MACs.
 *
 * @mixin ProductRevision
 */
final class ProductRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->payload;

        return [
            'schema_version' => $payload['schema_version'],
            'product_id' => $this->product->public_id,
            'revision' => $this->revision,
            'name' => $payload['name'],
            'sku' => $payload['sku'],
            'description_fa' => $payload['description_fa'],
            'pricing' => $payload['pricing'],
            'attributes' => $payload['attributes'],
            'image_asset_ids' => $payload['image_asset_ids'],
            'category_path' => $payload['category_path'],
            'evidence' => $payload['evidence'],
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
