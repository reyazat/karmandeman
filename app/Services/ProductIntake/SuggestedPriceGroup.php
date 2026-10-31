<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;

/**
 * The products of one grouped price question (FR-032, ADR-035): the first
 * products of the batch, by id, still waiting for Gate A whose current
 * revision carries a suggested price and a name. The list is the same for as long as
 * the batch version is, since every product change bumps it, so an answer
 * naming that version acts on exactly the rows that were shown.
 */
final readonly class SuggestedPriceGroup
{
    public const MAX = 20;

    public function __construct(private ProductRepositoryInterface $products) {}

    /**
     * @return list<array{Product, ProductRevision}>
     */
    public function list(Workspace $workspace, Project $project): array
    {
        $found = [];
        foreach ($this->products->activeForProject($workspace, $project) as $product) {
            // Only a waiting product with a price that needs confirming can hold a suggested price.
            if (($product->status === ProductStatus::Approved && $product->approved_revision === $product->current_revision)
                || ! $product->needs_confirmation || $product->price_missing) {
                continue;
            }
            $revision = $this->products->revision($product, $product->current_revision);
            // A product without a name has no label to be told apart by and needs its own card anyway.
            if ($revision !== null && ($revision->payload['pricing']['status'] ?? null) === 'suggested'
                && ($revision->payload['name']['value'] ?? null) !== null) {
                $found[] = [$product, $revision];
                if (count($found) === self::MAX) {
                    break;
                }
            }
        }

        return $found;
    }
}
