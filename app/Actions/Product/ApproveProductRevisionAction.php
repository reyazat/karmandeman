<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProductStatus;
use App\Exceptions\ProductRequiresConfirmation;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use DomainException;

/**
 * Gate A (PRD E05): the user approves exactly the revision they reviewed.
 * A stale revision is a conflict; suggested values must be confirmed first.
 */
final readonly class ApproveProductRevisionAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProductRepositoryInterface $products,
        private ProductDraftNormalizer $normalizer,
    ) {}

    /**
     * @return array{ProductRevision, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $productPublicId, int $revision): array
    {
        /** @var array{ProductRevision, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'product.approve', $key, ['product' => $productPublicId, 'revision' => $revision],
            function () use ($workspace, $user, $productPublicId, $revision): ProductRevision {
                $this->consent->execute($workspace);
                $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                if ($product->status === ProductStatus::Archived) {
                    throw new DomainException('PRODUCT_ARCHIVED');
                }
                if ($product->current_revision !== $revision) {
                    throw new DomainException('PRODUCT_REVISION_CONFLICT');
                }
                $current = $this->products->revision($product, $revision)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                // A second click with a fresh key approves nothing new.
                if ($current->approved_at !== null) {
                    return $current;
                }
                $unconfirmed = $this->normalizer->fieldsRequiringConfirmation($current->payload);
                if ($unconfirmed !== []) {
                    throw new ProductRequiresConfirmation($unconfirmed);
                }

                return $this->products->approve($product, $current, $user);
            },
        );

        return $result;
    }
}
