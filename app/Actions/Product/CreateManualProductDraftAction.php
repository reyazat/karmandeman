<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Wallet\EnsureInputCreditAction;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Seller types a product into an open batch (Step 3 slice, ADR-016). No AI
 * extraction: every value is a manual_override of this user, and photos
 * must be assets of the same batch (ADR-023).
 */
final readonly class CreateManualProductDraftAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private EnsureInputCreditAction $credit,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private ProductRevisionBuilder $builder,
        private AiWorkflowRunRepositoryInterface $runs,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array{ProductRevision, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, array $draft): array
    {
        /** @var array{ProductRevision, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'product.create', $key, ['project' => $projectPublicId, 'draft' => $draft],
            function () use ($workspace, $user, $projectPublicId, $draft): ProductRevision {
                $this->consent->execute($workspace);
                $this->credit->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if (! $project->state->isOpenForInput()) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_PRODUCTS');
                }
                // Photos an open extraction run is reading stay with the run (ADR-032, ADR-041).
                $reading = $this->runs->openForProject($project)->input_asset_ids ?? [];
                if (array_intersect(array_map('strtolower', (array) ($draft['image_asset_ids'] ?? [])), $reading) !== []) {
                    throw new DomainException('PRODUCT_IMAGES_BEING_EXTRACTED');
                }

                $revision = $this->products->createWithRevision($workspace, $project, $user, $this->builder->build($workspace, $project, $draft));
                $this->projects->bumpVersion($workspace, $project->id);

                return $revision;
            },
        );

        return $result;
    }
}
