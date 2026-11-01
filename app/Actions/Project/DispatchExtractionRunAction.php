<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Enums\AiRunStatus;
use App\Enums\ProjectState;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use Carbon\CarbonImmutable;

/**
 * Starts a queued extraction run on n8n (ADR-032). Runs from the worker
 * after the batch closed; transport errors are thrown so the job retries.
 * A run that was cancelled, timed out, erased or already started is left alone.
 */
final readonly class DispatchExtractionRunAction
{
    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private ProjectRepositoryInterface $projects,
        private AssetRepositoryInterface $assets,
        private AIWorkflowService $ai,
        private FailAIWorkflowRunAction $fail,
    ) {}

    public function execute(int $runId): void
    {
        $run = $this->runs->find($runId);
        // Only a queued run starts: a retry after n8n accepted it would run the model twice.
        if ($run === null || $run->status !== AiRunStatus::Queued || $run->deadline_at->isPast()) {
            return;
        }
        // Erasure started after the batch closed: send nothing (FR-086).
        if ($run->workspace->status !== 'active') {
            return;
        }
        // Switched off after the batch closed: reopen it now, not at the deadline.
        if (! $this->ai->extractionEnabled()) {
            $this->fail->execute($run->id, 'EXTRACTION_DISABLED');

            return;
        }
        $project = $this->projects->findForWorkspace($run->workspace, $run->project_id);
        if ($project === null || $project->state !== ProjectState::Extracting) {
            return;
        }

        $this->runs->countAttempt($run);
        $run->refresh();
        $envelope = $this->ai->extractionEnvelope($run, $project, $this->assets->inProjectByPublicIds($project, $run->input_asset_ids));
        // A retry after a lost answer is safe: only the execution that claims the run works on it (ADR-038).
        $this->ai->startExtraction($envelope);
        $this->runs->markDispatched($run, CarbonImmutable::now());
    }
}
