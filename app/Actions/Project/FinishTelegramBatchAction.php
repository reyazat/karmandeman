<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Telegram\TelegramBatchReview;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `/done` in the bot (ADR-027): closes the input of the bot's current batch
 * (ADR-028), or shows it again once in review (TelegramBatchReview): a
 * grouped price question, one card per product still waiting for Gate A or,
 * when none is, the batch approval button for the version shown. Refusals are
 * answered.
 */
final readonly class FinishTelegramBatchAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private FinishProjectInputAction $finish,
        private TelegramBatchReview $review,
    ) {}

    /**
     * @return list<BotReply> the summary first, then one card per waiting product
     */
    public function execute(Workspace $workspace, User $user, string $messageKey): array
    {
        // The same current batch photos and /product use (ADR-028).
        $project = $this->projects->currentForBot($workspace);
        if ($project === null) {
            return [new BotReply('پروژه بازی نداری؛ با /new پروژه بساز.')];
        }
        if ($project->state === ProjectState::Extracting) {
            return [new BotReply("محصولات پروژه «{$project->name}» در حال استخراج از عکس‌هاست؛ پس از پایان خبر می‌دهم.")];
        }
        if (! in_array($project->state, [ProjectState::CollectingInput, ProjectState::ReviewRequired], true)) {
            return [new BotReply("پروژه جاری «{$project->name}» بسته است؛ با /projects پروژه دیگری انتخاب کن یا با /new بساز.")];
        }
        if ($project->state === ProjectState::CollectingInput) {
            try {
                [$project] = $this->finish->execute($workspace, $user, 'telegram-'.substr($messageKey, 0, 48), $project->public_id, $project->version);
            } catch (AuthorizationException) {
                return [new BotReply('پروژه یافت نشد.')];
            } catch (DomainException $e) {
                return [new BotReply(match ($e->getMessage()) {
                    'PROJECT_HAS_NO_PRODUCTS' => "پروژه «{$project->name}» هنوز محصولی ندارد؛ اول با /product محصول ثبت کن.",
                    'EXTRACTION_TOO_MANY_PHOTOS' => 'عکس‌های این پروژه برای یک استخراج بیش از '.config('karmandeman.ai.max_assets_per_run').' تاست؛ پروژه تازه‌ای با /new بساز.',
                    'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                    // The workspace lock refused it: erasure started after the webhook's check (FR-086).
                    'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                    default => 'پایان ورودی انجام نشد؛ دوباره /done را بفرست.',
                })];
            }
        }

        // The photos went to an n8n run (ADR-032); its callback sends the next message.
        if ($project->state === ProjectState::Extracting) {
            return [new BotReply("ورودی پروژه «{$project->name}» بسته شد و محصولات از عکس‌ها استخراج می‌شوند. پس از پایان خبر می‌دهم؛ همه مقدارها پیشنهادی خواهند بود.")];
        }

        return $this->review->replies($workspace, $project);
    }
}
