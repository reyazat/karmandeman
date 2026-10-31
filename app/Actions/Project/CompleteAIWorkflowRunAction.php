<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Product\RecordProductCandidatesAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\AI\ProductExtractionResult;
use App\Enums\AiRunStatus;
use App\Enums\ProjectState;
use App\Exceptions\WorkflowOutputRejected;
use App\Models\AiWorkflowRun;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use App\Services\AI\ProductExtractionOutputValidator;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Telegram\TelegramReplyService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Takes the signed n8n callback of an extraction run (ADR-032). The body is
 * untrusted even when signed: it is checked against the versioned schema,
 * the reported usage against the run caps and every cited photo against the
 * run's own inputs. Only an open run of a batch still extracting changes
 * anything; a late, repeated or erased callback is answered and ignored.
 */
final readonly class CompleteAIWorkflowRunAction
{
    public const RECORDED = 'recorded';

    public const ALREADY_RECORDED = 'already_recorded';

    public const DISCARDED = 'discarded';

    public const RUN_FAILED = 'run_failed';

    public const RUN_NOT_FOUND = 'RUN_NOT_FOUND';

    public const RUN_ALREADY_COMPLETED = 'RUN_ALREADY_COMPLETED';

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private UserRepositoryInterface $users,
        private ProductExtractionOutputValidator $validator,
        private RecordProductCandidatesAction $record,
        private FailAIWorkflowRunAction $fail,
        private LockActiveWorkspaceAction $lockActive,
        private EnsurePolicyConsentAction $consent,
        private WorkspaceFieldCipher $cipher,
        private AIWorkflowService $ai,
        private TelegramReplyService $replies,
    ) {}

    /**
     * @param  array<mixed>  $body  decoded callback JSON
     * @return string one of the outcome constants
     *
     * @throws DomainException RUN_NOT_FOUND or RUN_ALREADY_COMPLETED
     * @throws WorkflowOutputRejected after failing the run
     */
    public function execute(string $rawBody, array $body): string
    {
        $runId = $body['run_id'] ?? null;
        $run = is_string($runId) && preg_match('/^[0-9a-f-]{36}$/i', $runId) === 1
            ? $this->runs->findByPublicId(strtolower($runId)) : null;
        if ($run === null) {
            throw new DomainException(self::RUN_NOT_FOUND);
        }

        try {
            $result = $this->validator->validate($body, (int) config('karmandeman.ai.max_candidates_per_run'));
            $this->checkSources($run, $result);
        } catch (WorkflowOutputRejected $e) {
            Log::warning('ai.run_output_rejected', ['run_id' => $run->public_id, 'fields' => array_slice($e->fields, 0, 20)]);
            $this->fail->execute($run->id, 'OUTPUT_INVALID');

            throw $e;
        }

        return DB::transaction(function () use ($run, $rawBody, $result): string {
            // Workspace lock first, in the purge's order: erasure contains a late callback (FR-086).
            try {
                $this->lockActive->execute($run->workspace);
            } catch (DomainException) {
                return self::DISCARDED;
            }
            $run = $this->runs->lock($run);
            $mac = $this->cipher->mac($run->workspace, 'ai_workflow_runs.result', $rawBody);
            if (! $run->status->isOpen()) {
                if ($run->status !== AiRunStatus::Succeeded) {
                    return self::DISCARDED;
                }
                if (hash_equals((string) $run->result_mac, $mac)) {
                    return self::ALREADY_RECORDED;
                }
                throw new DomainException(self::RUN_ALREADY_COMPLETED);
            }

            if (! $result->succeeded) {
                $this->fail->closeLocked($run, (string) $result->errorCode, $result->usage);

                return self::RUN_FAILED;
            }
            if ($this->ai->usageOverCap($result->usage)) {
                Log::critical('ai.run_usage_over_cap', ['run_id' => $run->public_id, 'usage' => $result->usage]);
                $this->fail->closeLocked($run, 'USAGE_OVER_CAP', $result->usage);

                return self::RUN_FAILED;
            }

            $project = $this->projects->findForWorkspace($run->workspace, $run->project_id);
            if ($project === null || $project->state !== ProjectState::Extracting) {
                $this->runs->close($run, AiRunStatus::Cancelled, CarbonImmutable::now(), 'PROJECT_NOT_EXTRACTING', usage: $result->usage);

                return self::DISCARDED;
            }
            $requester = $this->users->find($run->requested_by);
            if ($requester === null || Gate::forUser($requester)->denies('update', $project)) {
                $this->fail->closeLocked($run, 'REQUESTER_NOT_AUTHORIZED', $result->usage);

                return self::RUN_FAILED;
            }
            try {
                $this->consent->execute($run->workspace);
            } catch (DomainException) {
                $this->fail->closeLocked($run, 'POLICY_CONSENT_REQUIRED', $result->usage);

                return self::RUN_FAILED;
            }

            $created = $this->record->execute($run->workspace, $project, $requester, $result->candidates, $run->input_asset_ids);
            $hasProducts = $this->products->activeForProject($run->workspace, $project)->isNotEmpty();
            $project = $this->projects->advance($project, $hasProducts ? ProjectState::ReviewRequired : ProjectState::CollectingInput);
            $this->runs->close($run, AiRunStatus::Succeeded, CarbonImmutable::now(), null, $mac, $created, $result->usage);

            $chatId = $this->users->telegramChatId($requester);
            if ($chatId !== null) {
                $this->replies->sendMessage($chatId, $hasProducts
                    ? "استخراج پروژه «{$project->name}» تمام شد: {$created} محصول پیشنهاد شد. همه مقدارها «پیشنهادی» هستند و پیش از تأیید باید بررسی شوند. برای دیدن کارت‌ها /done را بفرست."
                    : "در عکس‌های پروژه «{$project->name}» محصولی پیدا نشد. پروژه دوباره باز است؛ عکس دیگری بفرست یا محصول را با /product ثبت کن.");
            }

            return self::RECORDED;
        });
    }

    /**
     * A candidate may cite only photos this run was given (FR-030).
     *
     * @throws WorkflowOutputRejected
     */
    private function checkSources(AiWorkflowRun $run, ProductExtractionResult $result): void
    {
        $failed = [];
        foreach ($result->candidates as $index => $candidate) {
            foreach ($candidate['source_asset_ids'] as $position => $id) {
                if (! in_array(strtolower($id), $run->input_asset_ids, true)) {
                    $failed[] = "candidates.{$index}.source_asset_ids.{$position}";
                }
            }
        }
        if ($failed !== []) {
            throw new WorkflowOutputRejected($failed);
        }
    }
}
