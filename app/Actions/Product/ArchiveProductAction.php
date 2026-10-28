<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProductStatus;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use DomainException;

/**
 * "Reject" in item review (FR-041, ADR-017): the product leaves the batch and
 * frees its SKUs, but its revisions stay in the Product Repository.
 */
final readonly class ArchiveProductAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProductRepositoryInterface $products,
    ) {}

    /**
     * @return array{ProductRevision, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $productPublicId): array
    {
        /** @var array{ProductRevision, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'product.archive', $key, ['product' => $productPublicId],
            function () use ($workspace, $productPublicId): ProductRevision {
                $this->consent->execute($workspace);
                $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                if ($product->status !== ProductStatus::Archived) {
                    $this->products->archive($product);
                }

                return $this->products->revision($product, $product->current_revision)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
            },
        );

        return $result;
    }
}
