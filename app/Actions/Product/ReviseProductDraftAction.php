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
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Manual correction (FR-033) as a new immutable revision. Earlier revisions,
 * including an approved one, stay unchanged (ADR-016). A batch past approval
 * must be reopened first (ADR-029). Values the correction leaves unchanged keep
 * their provenance, so a confirmed suggestion still cites its photos (ADR-034).
 */
final readonly class ReviseProductDraftAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProductRepositoryInterface $products,
        private ProjectRepositoryInterface $projects,
        private ProductRevisionBuilder $builder,
        private ProjectSnapshotSigner $signer,
        private AiWorkflowRunRepositoryInterface $runs,
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
                $project = $product->project_id === null ? null : $this->projects->findForWorkspace($workspace, $product->project_id);
                // A cancelled batch is closed; only "reject" stays open to free SKUs (ADR-019).
                if ($project?->state === ProjectState::Cancelled) {
                    throw new DomainException('PROJECT_CANCELLED');
                }
                // An approved batch is corrected only after reopen, so no two approved
                // versions of one product exist side by side (PRD §6, ADR-029).
                if ($project?->state->hasApprovedSnapshot() === true) {
                    throw new DomainException('PROJECT_REOPEN_REQUIRED');
                }
                if ($product->current_revision !== $expectedRevision) {
                    throw new DomainException('PRODUCT_REVISION_CONFLICT');
                }
                // Photos an open extraction run is reading stay with the run (ADR-041).
                $reading = $project === null ? [] : ($this->runs->openForProject($project)->input_asset_ids ?? []);
                if (array_intersect(array_map('strtolower', (array) ($draft['image_asset_ids'] ?? [])), $reading) !== []) {
                    throw new DomainException('PRODUCT_IMAGES_BEING_EXTRACTED');
                }

                $replaced = $this->products->revision($product, $expectedRevision)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                // Provenance is carried only from a payload that still matches its MAC.
                $trusted = hash_equals((string) $replaced->payload_mac, $this->signer->payloadMac($workspace, $replaced));
                $revision = $this->products->addRevision($product, $user, $this->builder->build($workspace, $project, $draft, previous: $trusted ? $replaced->payload : null));
                $this->projects->bumpVersion($workspace, $product->project_id);

                return $revision;
            },
        );

        return $result;
    }
}
