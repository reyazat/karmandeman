<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Idempotency\RunIdempotentMutationAction;
use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Enums\ProjectState;
use App\Jobs\AI\DispatchProductExtraction;
use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * Close the batch input (PRD §6, ADR-018). With extraction on, photos no
 * product has claimed go to one n8n run and the batch waits in `extracting`
 * (ADR-032); otherwise the manual path goes straight to review.
 */
final readonly class FinishProjectInputAction
{
    /** Far above any batch the upload throttle allows; the run cap is checked after filtering. */
    private const PHOTO_SCAN_LIMIT = 1000;

    public function __construct(
        private RunIdempotentMutationAction $idempotency,
        private EnsurePolicyConsentAction $consent,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private AssetRepositoryInterface $assets,
        private AiWorkflowRunRepositoryInterface $runs,
        private AIWorkflowService $ai,
    ) {}

    /**
     * @return array{Project, bool}
     */
    public function execute(Workspace $workspace, User $user, string $key, string $projectPublicId, int $version): array
    {
        /** @var array{Project, bool} $result */
        $result = $this->idempotency->execute(
            $workspace, $user->id, 'project.finish_input', $key, ['project' => $projectPublicId, 'version' => $version],
            function () use ($workspace, $user, $projectPublicId, $version): Project {
                $this->consent->execute($workspace);
                // The workspace row lock taken by the idempotency wrapper serializes this read.
                $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
                    ?? throw new DomainException('PROJECT_NOT_FOUND');
                Gate::forUser($user)->authorize('update', $project);
                if ($project->state !== ProjectState::CollectingInput) {
                    throw new DomainException('PROJECT_STATE_CONFLICT');
                }
                if ($project->version !== $version) {
                    throw new DomainException('PROJECT_VERSION_CONFLICT');
                }
                if ($this->ai->extractionEnabled()) {
                    $max = (int) config('karmandeman.ai.max_assets_per_run');
                    $photos = $this->newPhotos($workspace, $project);
                    if (count($photos) > $max) {
                        throw new DomainException('EXTRACTION_TOO_MANY_PHOTOS');
                    }
                    if ($photos !== []) {
                        return $this->startExtraction($project, $user, $photos);
                    }
                }
                if ($this->products->activeForProject($workspace, $project)->isEmpty()) {
                    throw new DomainException('PROJECT_HAS_NO_PRODUCTS');
                }

                return $this->projects->advance($project, ProjectState::ReviewRequired);
            },
        );

        return $result;
    }

    /**
     * Photos no product uses yet and no earlier run has read: not claimed by a
     * bot product, not cited by an API product's current revision, and not
     * an input of a run that succeeded, even one that found nothing (ADR-032).
     *
     * @return list<Asset>
     */
    private function newPhotos(Workspace $workspace, Project $project): array
    {
        $used = $this->runs->extractedAssetIds($project);
        foreach ($this->products->activeForProject($workspace, $project) as $product) {
            $revision = $this->products->revision($product, $product->current_revision);
            array_push($used, ...($revision === null ? [] : $revision->payload['image_asset_ids']));
        }

        return array_values(array_filter(
            $this->assets->unclaimedInProject($project, self::PHOTO_SCAN_LIMIT),
            fn (Asset $asset): bool => ! in_array($asset->public_id, $used, true),
        ));
    }

    /**
     * @param  list<Asset>  $photos
     */
    private function startExtraction(Project $project, User $user, array $photos): Project
    {
        $project = $this->projects->advance($project, ProjectState::Extracting);
        $run = $this->runs->create(
            $project, $user, AiWorkflowRun::WORKFLOW_PRODUCT_EXTRACTION, (string) config('karmandeman.ai.workflow_version'),
            array_map(fn ($asset): string => $asset->public_id, $photos),
            CarbonImmutable::now()->addMinutes((int) config('karmandeman.ai.run_timeout_minutes')),
        );
        // Inserted in this transaction like the Telegram outbox (ADR-008): no run without its start.
        DispatchProductExtraction::dispatch($run->id)->onConnection('ai')->onQueue('ai')->beforeCommit();

        return $project;
    }
}
