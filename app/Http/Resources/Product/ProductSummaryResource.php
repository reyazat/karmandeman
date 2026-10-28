<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Batch summary counted by the server (FR-040, ADR-017, ADR-020).
 *
 * @property array{total: int, needs_review: int, approved: int, archived: int, ready_for_approval: int, needs_confirmation: int, price_missing: int, image_missing: int, duplicate_suspected: int} $resource
 */
final class ProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
