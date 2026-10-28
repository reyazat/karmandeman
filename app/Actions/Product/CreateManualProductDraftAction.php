<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use DomainException;

/**
 * Seller types a product into an open batch (Step 3 slice, ADR-016). No AI,
 * no file: every value is a manual_override of this user.
 */
final readonly class CreateManualProductDraftAction
{
    private const OPEN_PROJECT_STATES = [
        ProjectState::CollectingInput, ProjectState::NeedsClarification, ProjectState::ReviewRequired,
    ];

    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private ProductRevisionBuilder $builder,
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
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                if (! in_array($project->state, self::OPEN_PROJECT_STATES, true)) {
                    throw new DomainException('PROJECT_NOT_ACCEPTING_PRODUCTS');
                }

                $revision = $this->products->createWithRevision($workspace, $project, $user, $this->builder->build($workspace, $draft));
                $this->projects->bumpVersion($workspace, $project->id);

                return $revision;
            },
        );

        return $result;
    }
}
