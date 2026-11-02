<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;

/**
 * The batch a photo, CSV or /product in the bot goes to (ADR-041). A batch
 * stays open for input until it is approved, so the current one takes it
 * while collecting, extracting or in review. An approved or cancelled batch is
 * closed (FR-022): new input never joins it silently, it opens a new batch,
 * which becomes current. The caller answers with the notice, so the seller
 * always knows where the input went. Runs in the caller's transaction, which
 * keeps the workspace lock it takes.
 */
final readonly class ResolveTelegramInputProjectAction
{
    public function __construct(
        private LockActiveWorkspaceAction $lockActive,
        private ProjectRepositoryInterface $projects,
        private CreateProjectAction $createProject,
    ) {}

    /**
     * @return array{Project, string|null} the batch, and the notice when a new one was opened
     */
    public function execute(Workspace $workspace): array
    {
        // Read under the workspace lock: an album sent after approval arrives as parallel
        // updates, and each must find the batch the first one opened, not open its own.
        $this->lockActive->execute($workspace);
        $current = $this->projects->currentForBot($workspace);
        if ($current !== null && $current->state->isOpenForInput()) {
            return [$current, null];
        }
        $project = $this->createProject->execute($workspace);
        $notice = $current === null
            ? "پروژه «{$project->name}» ساخته شد و این ورودی در آن ثبت می‌شود."
            : "پروژه «{$current->name}» ".($current->state === ProjectState::Cancelled ? 'لغو شده' : 'تأیید شده')
                ." بود؛ پروژه تازه «{$project->name}» ساختم و این ورودی در آن ثبت می‌شود."
                .($current->state === ProjectState::Cancelled ? '' : ' برای اصلاح پروژه قبلی، به کارت محصولش پاسخ بده یا عکس را در پاسخ به کارت بفرست.');

        return [$project, $notice];
    }
}
