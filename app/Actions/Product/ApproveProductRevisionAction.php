<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProductStatus;
use App\Enums\ProjectState;
use App\Exceptions\ProductRequiresConfirmation;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

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
        private ProjectRepositoryInterface $projects,
        private ProductDraftNormalizer $normalizer,
        private ProjectSnapshotSigner $signer,
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
                Gate::forUser($user)->authorize('update', $product);
                if ($product->status === ProductStatus::Archived) {
                    throw new DomainException('PRODUCT_ARCHIVED');
                }
                // A cancelled batch is closed; only "reject" stays open to free SKUs (ADR-019).
                if ($product->project_id !== null
                    && $this->projects->findForWorkspace($workspace, $product->project_id)?->state === ProjectState::Cancelled) {
                    throw new DomainException('PROJECT_CANCELLED');
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

                // Whole seconds: the column keeps no fraction, and the MAC must match what is read back.
                $at = CarbonImmutable::now()->startOfSecond();
                $approved = $this->products->approve($product, $current, $user, $at, $this->signer->approvalMac($workspace, $product, $current, $at));
                $this->projects->bumpVersion($workspace, $product->project_id);

                return $approved;
            },
        );

        return $result;
    }
}
