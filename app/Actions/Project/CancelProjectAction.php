<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\AiRunStatus;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Cancel a batch (PRD §6, FR-063, ADR-019). Products are left as they are:
 * an approved record is independent of its batch (PRD E06-a).
 */
final readonly class CancelProjectAction
{
    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private AiWorkflowRunRepositoryInterface $runs,
    ) {}

    /**
     * @return array{Project, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.cancel', $key, ['project' => $projectPublicId, 'version' => $version],
            function () use ($workspace, $user, $projectPublicId, $version): Project {
                $this->consent->execute($workspace);
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if (! $project->state->isCancellable()) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }

                // A run still open would otherwise write products into the cancelled batch (ADR-032).
                $run = $this->runs->openForProject($project);
                if ($run !== null) {
                    $this->runs->close($this->runs->lock($run), AiRunStatus::Cancelled, CarbonImmutable::now(), 'PROJECT_CANCELLED');
                }

                return $this->projects->advance($project, ProjectState::Cancelled);
            },
        );

        return $result;
    }
}
