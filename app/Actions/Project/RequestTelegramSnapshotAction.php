<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Jobs\Telegram\SendTelegramSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The bot's "JSON file" button (ADR-030): queues the file of exactly the
 * snapshot the approval answer named. Reading it again costs no credit and
 * runs no AI. The worker checks everything again before sending.
 */
final readonly class RequestTelegramSnapshotAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ProjectSnapshotRepositoryInterface $snapshots,
    ) {}

    public function execute(Workspace $workspace, User $user, string $projectPublicId, int $snapshotNumber): string
    {
        $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId);
        if ($project === null || Gate::forUser($user)->denies('view', $project)) {
            return 'پروژه یافت نشد.';
        }
        // A reopened or cancelled batch has no current approval (ADR-019).
        $latest = $project->state->hasApprovedSnapshot() ? $this->snapshots->latestFor($project) : null;
        if ($latest === null) {
            return "پروژه «{$project->name}» نسخه نهایی معتبر ندارد؛ پس از تأیید دوباره پروژه فایل را بگیر.";
        }
        if ($latest->number !== $snapshotNumber) {
            return 'پس از این دکمه نسخه نهایی تازه‌تری ثبت شده است؛ دکمه پیام تأیید تازه را بزن.';
        }
        $limit = 'telegram-snapshot:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            return 'فایل‌ها خیلی سریع درخواست شدند؛ یک دقیقه صبر کن.';
        }
        RateLimiter::hit($limit, 60);

        // Inserted in the webhook transaction like the outbox (ADR-008): the "preparing"
        // answer and the job commit together, so neither exists without the other.
        SendTelegramSnapshot::dispatch($user->id, $project->public_id, $snapshotNumber)
            ->onConnection('telegram')->onQueue('telegram')->beforeCommit();

        return "فایل JSON نسخه نهایی شماره {$snapshotNumber} پروژه «{$project->name}» در حال آماده‌سازی است.";
    }
}
