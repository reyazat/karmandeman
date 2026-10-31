<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\AiRunStatus;
use App\Enums\ProjectState;
use App\Models\AiWorkflowRun;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ends an open extraction run without a result (ADR-032): its start failed,
 * its deadline passed or n8n reported failure. The batch goes back to
 * collecting input, so the seller can send /done again or add products by
 * hand. Nothing was stored from the run, and no credit was reserved.
 */
final readonly class FailAIWorkflowRunAction
{
    public const TIMED_OUT = 'TIMED_OUT';

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private ProjectRepositoryInterface $projects,
        private UserRepositoryInterface $users,
        private LockActiveWorkspaceAction $lockActive,
        private TelegramReplyService $replies,
    ) {}

    /**
     * @param  array<string, int|string>|null  $usage
     */
    public function execute(int $runId, string $errorCode, ?array $usage = null): void
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return;
        }
        DB::transaction(function () use ($run, $errorCode, $usage): void {
            // Workspace lock first, in the purge's order (FR-086).
            try {
                $this->lockActive->execute($run->workspace);
            } catch (DomainException) {
                return;
            }
            $run = $this->runs->lock($run);
            if ($run->status->isOpen()) {
                $this->closeLocked($run, $errorCode, $usage);
            }
        });
    }

    /**
     * The same ending inside a caller that already holds the workspace and run locks.
     *
     * @param  array<string, int|string>|null  $usage
     */
    public function closeLocked(AiWorkflowRun $run, string $errorCode, ?array $usage = null): void
    {
        $this->runs->close($run, $errorCode === self::TIMED_OUT ? AiRunStatus::TimedOut : AiRunStatus::Failed, CarbonImmutable::now(), $errorCode, usage: $usage);

        $project = $this->projects->findForWorkspace($run->workspace, $run->project_id);
        if ($project === null || $project->state !== ProjectState::Extracting) {
            return;
        }
        $this->projects->advance($project, ProjectState::CollectingInput);
        $requester = $this->users->find($run->requested_by);
        $chatId = $requester === null ? null : $this->users->telegramChatId($requester);
        if ($chatId !== null) {
            $this->replies->sendMessage($chatId, $errorCode === self::TIMED_OUT
                ? "استخراج محصولات پروژه «{$project->name}» بیش از حد طول کشید و متوقف شد. پروژه دوباره باز است؛ /done را دوباره بفرست یا محصول‌ها را با /product ثبت کن."
                : "استخراج محصولات پروژه «{$project->name}» انجام نشد. پروژه دوباره باز است؛ /done را دوباره بفرست یا محصول‌ها را با /product ثبت کن.");
        }
    }
}
