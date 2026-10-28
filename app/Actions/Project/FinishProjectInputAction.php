<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;

/**
 * Close the batch input (PRD §6, ADR-018). The manual path goes straight to
 * review; AI extraction (Step 4) will sit between these two states.
 */
final readonly class FinishProjectInputAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
    ) {}

    /**
     * @return array{Project, bool}
     */
    public function execute(Workspace $workspace, int $userId, string $key, string $projectPublicId, int $version): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $userId, 'project.finish_input', $key, ['project' => $projectPublicId, 'version' => $version],
            function () use ($workspace, $projectPublicId, $version): Project {
                $this->consent->execute($workspace);
                // The workspace row lock taken by the idempotency wrapper serializes this read.
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                if ($project->state !== ProjectState::CollectingInput) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }
                if ($this->products->activeForProject($workspace, $project)->isEmpty()) {
                    throw new DomainException('PROJECT_HAS_NO_PRODUCTS');
                }

                return $this->projects->advance($project, ProjectState::ReviewRequired);
            },
        );

        return $result;
    }
}
