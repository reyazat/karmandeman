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
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Manual correction (FR-033) as a new immutable revision. Earlier revisions,
 * including an approved one, stay unchanged (ADR-016).
 */
final readonly class ReviseProductDraftAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProductRepositoryInterface $products,
        private ProjectRepositoryInterface $projects,
        private ProductRevisionBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array{ProductRevision, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $productPublicId, int $expectedRevision, array $draft): array
    {
        /** @var array{ProductRevision, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'product.revise', $key,
            ['product' => $productPublicId, 'expected_revision' => $expectedRevision, 'draft' => $draft],
            function () use ($workspace, $user, $productPublicId, $expectedRevision, $draft): ProductRevision {
                $this->consent->execute($workspace);
                // The workspace row lock taken by the idempotency wrapper serializes this read.
                $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $product);
                if ($product->status === ProductStatus::Archived) {
                    throw new DomainException('PRODUCT_ARCHIVED');
                }
                // A cancelled batch is closed; only "reject" stays open to free SKUs (ADR-019).
                if ($product->project_id !== null
                    && $this->projects->findForWorkspace($workspace, $product->project_id)?->state === ProjectState::Cancelled) {
                    throw new DomainException('PROJECT_CANCELLED');
                }
                if ($product->current_revision !== $expectedRevision) {
                    throw new DomainException('PRODUCT_REVISION_CONFLICT');
                }

                $revision = $this->products->addRevision($product, $user, $this->builder->build($workspace, $draft));
                $this->projects->bumpVersion($workspace, $product->project_id);

                return $revision;
            },
        );

        return $result;
    }
}
