<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProductStatus;
use App\Enums\ProjectState;
use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\ProjectProductsNotApproved;
use App\Models\Product;
use App\Models\ProjectSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use App\Services\ProductIntake\ApprovedRevisionVerifier;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Freeze the approved batch (PRD §6, FR-043, ADR-018). Every product must
 * already have passed Gate A on its current revision; nothing is approved
 * here on the user's behalf.
 */
final readonly class ApproveProjectProductsAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private ProjectSnapshotRepositoryInterface $snapshots,
        private ProjectSnapshotSigner $signer,
        private ApprovedRevisionVerifier $verifier,
    ) {}

    /**
     * @return array{ProjectSnapshot, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version): array
    {
        /** @var array{ProjectSnapshot, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.approve_products', $key, ['project' => $projectPublicId, 'version' => $version],
            function () use ($workspace, $user, $projectPublicId, $version): ProjectSnapshot {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                if ($project->state !== ProjectState::ReviewRequired) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }

                $products = $this->products->activeForProject($workspace, $project);
                if ($products->isEmpty()) {
                    throw new DomainException('PROJECT_HAS_NO_PRODUCTS');
                }
                $pending = $products->reject(fn (Product $product): bool => $product->status === ProductStatus::Approved);
                if ($pending->isNotEmpty()) {
                    throw new ProjectProductsNotApproved($pending->map(fn (Product $product): string => $product->public_id)->values()->all());
                }

                $items = [];
                foreach ($products as $product) {
                    $revision = $product->approved_revision === null ? null : $this->products->revision($product, $product->approved_revision);
                    if ($revision === null || $revision->revision !== $product->current_revision) {
                        throw new ProjectProductsNotApproved([$product->public_id]);
                    }
                    // A forged approval stamp must not be frozen into a signed snapshot (ADR-019).
                    try {
                        $this->verifier->verify($workspace, $product, $revision);
                    } catch (ProductIntegrityFailed $e) {
                        Log::critical('product.integrity_failed', ['product' => $e->productId, 'reason' => $e->getMessage()]);

                        throw $e;
                    }
                    // The public id is signed too, so an export cannot be relabelled.
                    $items[] = [
                        'product_id' => $product->id, 'product' => $product->public_id,
                        'revision' => $revision->revision, 'payload_mac' => (string) $revision->payload_mac,
                    ];
                }

                $number = $this->snapshots->nextNumber($project);
                $snapshot = $this->snapshots->create($project, $user, $number, $items, $this->signer->sign($workspace, $project->id, $number, $items));
                $this->projects->advance($project, ProjectState::ProductApproved);

                return $snapshot;
            },
        );

        return $result;
    }
}
