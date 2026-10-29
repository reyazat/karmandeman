<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Support\Facades\Gate;

/**
 * `/cancel` in the bot (ADR-031): offers to cancel the bot's current batch
 * (ADR-028), with a confirm button for exactly the version shown. Cancelling
 * cannot be undone, so the first message only asks. Writes nothing.
 */
final readonly class PrepareTelegramProjectCancellationAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
    ) {}

    public function execute(Workspace $workspace, User $user): BotReply
    {
        $project = $this->projects->currentForBot($workspace);
        if ($project === null || Gate::forUser($user)->denies('update', $project)) {
            return new BotReply('پروژه جاری نداری؛ با /projects پروژه‌ای انتخاب کن.');
        }
        if (! $project->state->isCancellable()) {
            return new BotReply("پروژه جاری «{$project->name}» قابل لغو نیست؛ با /projects پروژه دیگری انتخاب کن.");
        }

        $count = $this->products->activeForProject($workspace, $project)->count();

        return new BotReply(
            "پروژه «{$project->name}» با {$count} محصول فعال لغو شود؟\n"
            .'لغو برگشت ندارد و این پروژه دیگر ورودی یا تأیید نمی‌گیرد. محصولات حذف نمی‌شوند'
            .($project->state === ProjectState::ProductApproved ? ' ولی نسخه نهایی این پروژه دیگر خروجی معتبر نیست' : '')
            .'. اگر نمی‌خواهی، این پیام را نادیده بگیر.',
            [[['text' => 'بله، پروژه لغو شود', 'callback_data' => "project:cancel:{$project->public_id}:{$project->version}"]]],
        );
    }
}
