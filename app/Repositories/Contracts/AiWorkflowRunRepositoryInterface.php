<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\AiRunStatus;
use App\Models\AiWorkflowRun;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

interface AiWorkflowRunRepositoryInterface
{
    /**
     * @param  list<string>  $inputAssetIds
     */
    public function create(Project $project, User $requester, string $workflow, string $workflowVersion, array $inputAssetIds, CarbonImmutable $deadline): AiWorkflowRun;

    public function find(int $id): ?AiWorkflowRun;

    /** Row lock on the run; the caller holds the workspace lock first, in the purge's order. */
    public function lock(AiWorkflowRun $run): AiWorkflowRun;

    public function findByPublicId(string $publicId): ?AiWorkflowRun;

    /** The run still able to change the batch, if any. */
    public function openForProject(Project $project): ?AiWorkflowRun;

    /**
     * Public ids of the photos every succeeded run of this batch was given.
     *
     * @return list<string>
     */
    public function extractedAssetIds(Project $project): array;

    /** Record a dispatch attempt; the first successful one marks the run dispatched. */
    public function markDispatched(AiWorkflowRun $run, CarbonImmutable $at): void;

    public function countAttempt(AiWorkflowRun $run): void;

    /**
     * Give a locked, open run to one n8n execution (ADR-038). A queued run
     * becomes dispatched too, so a retried start job sends nothing more.
     */
    public function claim(AiWorkflowRun $run, string $execution, CarbonImmutable $at): void;

    /**
     * @param  array<string, int|string>|null  $usage
     */
    public function close(AiWorkflowRun $run, AiRunStatus $status, CarbonImmutable $at, ?string $errorCode = null, ?string $resultMac = null, ?int $candidates = null, ?array $usage = null): void;

    /**
     * Open runs past their deadline in active workspaces, oldest first. Runs
     * of an account being erased wait for the purge (FR-086).
     *
     * @return list<int>
     */
    public function overdueIds(CarbonImmutable $now, int $limit): array;
}
