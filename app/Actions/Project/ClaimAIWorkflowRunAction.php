<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\ProjectState;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lets exactly one n8n execution work on a run (ADR-038). n8n claims the run
 * after it accepted the start and before it reads a photo or calls the
 * model, so a repeated start, from a retried job or a lost answer, never
 * pays for the model twice. The same execution may claim again, so n8n can
 * retry a claim whose answer it lost; every claim runs every check again.
 * A run that can no longer change the batch, or has too little time left
 * for the model, is refused, so its photos never leave and its model call is
 * never made.
 */
final readonly class ClaimAIWorkflowRunAction
{
    public const SCHEMA_VERSION = 'product-extraction.claim.v1';

    public const CLAIMED = 'claimed';

    public const CLAIM_INVALID = 'CLAIM_INVALID';

    public const RUN_NOT_FOUND = 'RUN_NOT_FOUND';

    public const RUN_ALREADY_CLAIMED = 'RUN_ALREADY_CLAIMED';

    public const RUN_CLOSED = 'RUN_CLOSED';

    /** Downloads, model work and the callback must fit before the deadline. */
    public const MIN_REMAINING_SECONDS = 300;

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private ProjectRepositoryInterface $projects,
        private LockActiveWorkspaceAction $lockActive,
        private FailAIWorkflowRunAction $fail,
        private AIWorkflowService $ai,
        private EnsurePolicyConsentAction $consent,
    ) {}

    /**
     * @param  array<mixed>  $body  decoded, signed claim JSON
     *
     * @throws DomainException one of the refusal constants
     */
    public function execute(array $body): string
    {
        $keys = array_keys($body);
        sort($keys);
        $runId = $body['run_id'] ?? null;
        $execution = $body['execution_id'] ?? null;
        if ($keys !== ['execution_id', 'run_id', 'schema_version'] || ($body['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! is_string($runId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $runId) !== 1
            || ! is_string($execution) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $execution) !== 1) {
            throw new DomainException(self::CLAIM_INVALID);
        }
        $run = $this->runs->findByPublicId($runId);
        if ($run === null) {
            throw new DomainException(self::RUN_NOT_FOUND);
        }

        // Refusals are returned, not thrown, so closing a switched-off run is committed.
        $outcome = DB::transaction(function () use ($run, $execution): string {
            // Workspace lock first, in the purge's order: an account being erased sends nothing (FR-086).
            try {
                $this->lockActive->execute($run->workspace);
            } catch (DomainException) {
                return self::RUN_CLOSED;
            }
            $run = $this->runs->lock($run);
            if ($run->claimed_by_execution !== null && ! hash_equals($run->claimed_by_execution, $execution)) {
                return $run->status->isOpen() ? self::RUN_ALREADY_CLAIMED : self::RUN_CLOSED;
            }
            $project = $this->projects->findForWorkspace($run->workspace, $run->project_id);
            if (! $run->status->isOpen() || $project === null || $project->state !== ProjectState::Extracting
                || $run->deadline_at->getTimestamp() - CarbonImmutable::now()->getTimestamp() < self::MIN_REMAINING_SECONDS) {
                return self::RUN_CLOSED;
            }
            // Switched off, or the policy no longer names the provider: reopen the batch now, before photos leave.
            if (! $this->ai->canProcessRun($run)) {
                $this->fail->closeLocked($run, 'EXTRACTION_DISABLED');

                return self::RUN_CLOSED;
            }
            // The last gate before photos go to the provider: the seller accepted the policy in force.
            try {
                $this->consent->execute($run->workspace);
            } catch (DomainException) {
                $this->fail->closeLocked($run, 'POLICY_CONSENT_REQUIRED');

                return self::RUN_CLOSED;
            }
            if ($run->claimed_by_execution === null) {
                $this->runs->claim($run, $execution, CarbonImmutable::now());
            }

            return self::CLAIMED;
        });
        if ($outcome !== self::CLAIMED) {
            throw new DomainException($outcome);
        }

        return $outcome;
    }
}
