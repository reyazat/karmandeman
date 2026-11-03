<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\AiRunStatus;
use App\Enums\ProjectState;
use App\Models\AiWorkflowRun;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ends an open extraction run without a result (ADR-032): its start failed,
 * its deadline passed or n8n reported failure. The batch goes back to
 * collecting input, so the seller can send /done again or add products by
 * hand; a batch with products goes back to review instead (ADR-041).
 * Nothing was stored from the run, and its held credit is released.
 */
final readonly class FailAIWorkflowRunAction
{
    public const TIMED_OUT = 'TIMED_OUT';

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private CloseAIWorkflowRunAction $closeRun,
        private ProductRepositoryInterface $products,
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
            } else {
                $this->keepLateUsage($run, $usage);
            }
        });
    }

    /**
     * A run closed before its answer (timeout, cancel) still paid the provider:
     * the first usage reported for it is kept for reconciliation (ADR-043) and
     * nothing is charged. Only the run's own provider counts, so a callback
     * naming another one cannot stand in for the bill. The caller holds the
     * workspace and run locks.
     *
     * @param  array<string, int|string>|null  $usage
     */
    public function keepLateUsage(AiWorkflowRun $run, ?array $usage): void
    {
        if ($usage !== null && $usage['provider'] === 'openai' && $run->status !== AiRunStatus::Succeeded && $run->usage === null) {
            $this->runs->recordLateUsage($run, $usage);
        }
    }

    /**
     * The same ending inside a caller that already holds the workspace and run locks.
     *
     * @param  array<string, int|string>|null  $usage
     */
    public function closeLocked(AiWorkflowRun $run, string $errorCode, ?array $usage = null): void
    {
        $this->closeRun->execute($run, $errorCode === self::TIMED_OUT ? AiRunStatus::TimedOut : AiRunStatus::Failed, $errorCode, usage: $usage);
        $released = $run->reservation_entry_id === null ? '' : ' اعتبار رزروشده برگشت و چیزی کسر نشد.';

        $project = $this->projects->findForWorkspace($run->workspace, $run->project_id);
        if ($project === null || $project->state !== ProjectState::Extracting) {
            return;
        }
        // A batch that already has products goes back to review, where it can still be approved
        // and /done offers the unread photos again (ADR-041); without products, to input.
        $this->projects->advance($project, $this->products->activeForProject($run->workspace, $project)->isNotEmpty()
            ? ProjectState::ReviewRequired : ProjectState::CollectingInput);
        $requester = $this->users->find($run->requested_by);
        $chatId = $requester === null ? null : $this->users->telegramChatId($requester);
        if ($chatId !== null) {
            $this->replies->sendMessage($chatId, $errorCode === self::TIMED_OUT
                ? "استخراج محصولات پروژه «{$project->name}» بیش از حد طول کشید و متوقف شد.{$released} پروژه دوباره باز است؛ /done را دوباره بفرست یا محصول‌ها را با /product ثبت کن."
                : "استخراج محصولات پروژه «{$project->name}» انجام نشد.{$released} پروژه دوباره باز است؛ /done را دوباره بفرست یا محصول‌ها را با /product ثبت کن.");
        }
    }
}
