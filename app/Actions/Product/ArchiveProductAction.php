<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProductStatus;
use App\Enums\ProjectState;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\Gate;

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
        private ProjectRepositoryInterface $projects,
        private AssetRepositoryInterface $assets,
    ) {}

    /**
     * @param  int|null  $expectedRevision  the revision the user saw; null when the caller does not pin one (API)
     * @return array{ProductRevision, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $productPublicId, ?int $expectedRevision = null): array
    {
        /** @var array{ProductRevision, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'product.archive', $key,
            // Unpinned API requests keep the fingerprint they had before ADR-028.
            ['product' => $productPublicId] + ($expectedRevision === null ? [] : ['revision' => $expectedRevision]),
            function () use ($workspace, $user, $productPublicId, $expectedRevision): ProductRevision {
                $this->consent->execute($workspace);
                $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $product);
                // A pinned reject (the bot's card) acts only on the revision shown, and only
                // while the batch is still under review: an old card never reaches a
                // finished batch (ADR-028).
                if ($expectedRevision !== null) {
                    if ($product->current_revision !== $expectedRevision) {
                        throw new DomainException('PRODUCT_REVISION_CONFLICT');
                    }
                    $state = $product->project_id === null ? null : $this->projects->findForWorkspace($workspace, $product->project_id)?->state;
                    if (! in_array($state, [ProjectState::CollectingInput, ProjectState::NeedsClarification, ProjectState::ReviewRequired], true)) {
                        throw new DomainException('PROJECT_STATE_CONFLICT');
                    }
                }
                if ($product->status !== ProductStatus::Archived) {
                    $this->products->archive($product);
                    // A rejected product hands its bot photos back for the next /product (ADR-026).
                    $this->assets->releaseClaims($product);
                    $this->projects->bumpVersion($workspace, $product->project_id);
                }

                return $this->products->revision($product, $product->current_revision)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
            },
        );

        return $result;
    }
}
