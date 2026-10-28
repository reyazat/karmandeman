<?php

declare(strict_types=1);

namespace App\Http\Resources\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /** @var list<string>|null suspected duplicates, given only for a single product */
    private ?array $duplicates = null;

    /**
     * Not a constructor argument: collections build each item with its key.
     *
     * @param  list<string>  $productIds
     */
    public function withDuplicates(array $productIds): self
    {
        $this->duplicates = $productIds;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'project_id' => $this->project?->public_id,
            'status' => $this->status->value,
            'current_revision' => $this->current_revision,
            'approved_revision' => $this->approved_revision,
            // Review queue flags of the current revision (FR-031, ADR-017).
            'review' => [
                'needs_confirmation' => $this->needs_confirmation,
                'price_missing' => $this->price_missing,
                'image_missing' => $this->image_missing,
                // Near-duplicate warning (FR-034, ADR-020); nothing is merged or removed.
                'duplicate_suspected' => $this->duplicate_suspected,
                'duplicates' => $this->when($this->duplicates !== null, fn () => $this->duplicates),
            ],
            'current' => new ProductRecordResource($this->latestRevision),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
