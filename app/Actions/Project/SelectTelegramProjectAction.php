<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * The bot's "select" button under /projects (ADR-028): makes a batch open for input (ADR-041) the
 * current one for photos, /product and /done. No product data changes, so the
 * batch version stays.
 */
final readonly class SelectTelegramProjectAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    public function execute(Workspace $workspace, User $user, string $projectPublicId): string
    {
        // A selection is a write: like every mutation it waits for, and is refused
        // after, a deletion request (FR-086). Held to the end of the webhook transaction.
        // Answered, not thrown: a rollback would also drop the button's acknowledgement.
        try {
            $this->lockActive->execute($workspace);
        } catch (DomainException) {
            return 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.';
        }
        $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId);
        if ($project === null || Gate::forUser($user)->denies('view', $project)) {
            return 'پروژه یافت نشد.';
        }
        // Open until approval (ADR-041): a batch in extraction or review still takes photos.
        if (! $project->state->isOpenForInput()) {
            return "پروژه «{$project->name}» تأیید یا لغو شده است و انتخاب نمی‌شود؛ برای اصلاح، به کارت محصولش پاسخ بده.";
        }
        $this->projects->selectForBot($project);

        return "پروژه «{$project->name}» انتخاب شد؛ عکس، فایل CSV و /product به این پروژه می‌روند".match ($project->state) {
            ProjectState::CollectingInput => '.',
            ProjectState::Extracting => '. استخراج آن در جریان است و پس از پایان خبر می‌دهم.',
            default => ' و /done بررسی آن را نشان می‌دهد.',
        };
    }
}
