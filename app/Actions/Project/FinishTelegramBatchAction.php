<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ExtractionInputSelector;
use App\Services\Telegram\TelegramBatchReview;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `/done` in the bot (ADR-027): closes the input of the bot's current batch
 * (ADR-028), or quotes the paid extraction run its new photos need first
 * (ADR-039), or shows it again once in review (TelegramBatchReview): a
 * grouped price question, one card per product still waiting for Gate A or,
 * when none is, the batch approval button for the version shown. Refusals are
 * answered. Stored voices are not read by any run yet: each outcome says so
 * (ADR-047 owner decision 2).
 */
final readonly class FinishTelegramBatchAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private FinishProjectInputAction $finish,
        private CreateExtractionQuoteAction $quote,
        private TelegramBatchReview $review,
        private ExtractionInputSelector $inputs,
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
        // Approved or cancelled: input already opens a new batch by itself (ADR-041).
        if ($project->state === ProjectState::Cancelled) {
            return [new BotReply("پروژه جاری «{$project->name}» لغو شده است؛ عکس، فایل CSV یا /product بعدی خودش پروژه تازه می‌سازد.")];
        }
        if ($project->state->hasApprovedSnapshot()) {
            return [new BotReply("پروژه جاری «{$project->name}» تأیید شده است؛ عکس، فایل CSV یا /product بعدی خودش پروژه تازه می‌سازد. برای اصلاح این پروژه، به کارت محصولش پاسخ بده.")];
        }
        if (! in_array($project->state, [ProjectState::CollectingInput, ProjectState::ReviewRequired], true)) {
            return [new BotReply("پروژه «{$project->name}» هنوز آماده پایان ورودی نیست؛ کمی بعد /done را دوباره بفرست.")];
        }
        $inReview = $project->state === ProjectState::ReviewRequired;
        if ($project->state === ProjectState::CollectingInput || $inReview) {
            try {
                [$project] = $this->finish->execute($workspace, $user, 'telegram-'.substr($messageKey, 0, 48), $project->public_id, $project->version);
            } catch (AuthorizationException) {
                return [new BotReply('پروژه یافت نشد.')];
            } catch (DomainException $e) {
                if ($e->getMessage() === 'EXTRACTION_QUOTE_REQUIRED') {
                    // In review the seller also gets the cards of the products already there (ADR-041).
                    $quote = $this->quoteReply($workspace, $user, $messageKey, $project);

                    return $this->withVoiceNotice($workspace, $project, $inReview ? [$quote, ...$this->review->replies($workspace, $project)] : [$quote]);
                }
                // In review with nothing new: show the review again.
                if ($inReview && $e->getMessage() === 'PROJECT_STATE_CONFLICT') {
                    return $this->withVoiceNotice($workspace, $project, $this->review->replies($workspace, $project));
                }
                if ($e->getMessage() === 'PROJECT_HAS_NO_PRODUCTS') {
                    return $this->withVoiceNotice($workspace, $project, [new BotReply("پروژه «{$project->name}» هنوز محصولی ندارد؛ اول با /product محصول ثبت کن.")]);
                }

                return [new BotReply(match ($e->getMessage()) {
                    'EXTRACTION_PHOTOS_TOO_LARGE' => 'حجم عکس‌های این پروژه برای یک استخراج بیش از حد است؛ عکس‌های کم‌حجم‌تر را در پروژه تازه‌ای با /new بفرست یا محصول را با /product ثبت کن.',
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
            return $this->withVoiceNotice($workspace, $project, [new BotReply("محصولات پروژه «{$project->name}» از عکس‌ها استخراج می‌شوند. پس از پایان خبر می‌دهم؛ همه مقدارها پیشنهادی خواهند بود. عکسی که در این مدت بفرستی برای /done بعدی می‌ماند.")]);
        }

        return $this->withVoiceNotice($workspace, $project, $this->review->replies($workspace, $project));
    }

    /**
     * Voices are stored but no quote or run reads them until the owner sets
     * the voice rate and the v2 contracts exist (ADR-047): the seller is told
     * after the summary, so a spoken price is not assumed to be in the batch.
     *
     * @param  list<BotReply>  $replies
     * @return list<BotReply>
     */
    private function withVoiceNotice(Workspace $workspace, Project $project, array $replies): array
    {
        try {
            $count = (string) count($this->inputs->voices($workspace, $project));
        } catch (DomainException) {
            $count = 'بیش از 1000';
        }
        if ($count === '0') {
            return $replies;
        }
        $notice = new BotReply("{$count} ویس این پروژه ذخیره است ولی هنوز به متن یا محصول تبدیل نمی‌شود و در پیش‌فاکتور و استخراج نیست. مشخصاتی را که در ویس گفتی با /product یا پاسخ به کارت محصول بنویس.");

        return [...array_slice($replies, 0, 1), $notice, ...array_slice($replies, 1)];
    }

    /**
     * The new photos go to a paid run: the estimate and the most it can cost
     * first, with an accept button only when the credit for that most is
     * there (FR-072, ADR-039, ADR-042).
     */
    private function quoteReply(Workspace $workspace, User $user, string $messageKey, Project $project): BotReply
    {
        try {
            [$quote, , $available] = $this->quote->execute($workspace, $user, 'telegram-q-'.substr($messageKey, 0, 48), $project->public_id, $project->version);
        } catch (AuthorizationException|DomainException) {
            return new BotReply('پیش‌فاکتور استخراج ساخته نشد؛ دوباره /done را بفرست.');
        }
        if ($quote->accepted_at !== null) {
            return new BotReply('این پیش‌فاکتور قبلاً تأیید شده است.');
        }
        $photos = count($quote->input_asset_ids);
        $minutes = (int) ceil(max(0, $quote->expires_at->getTimestamp() - time()) / 60);
        $cap = $quote->capCredits();
        $markup = $quote->markupPercent() > 0 ? " به‌علاوه {$quote->markupPercent()} درصد کارمزد کارمند من" : '';
        $price = $quote->credits_total === $cap ? "حداکثر {$cap} اعتبار" : "حدود {$quote->credits_total} و حداکثر {$cap} اعتبار";
        $text = "استخراج محصولات از {$photos} عکس تازه پروژه «{$project->name}» {$price} هزینه دارد. موجودی قابل استفاده تو {$available} اعتبار است.";
        if ($available < $cap) {
            return new BotReply($text.' اعتبار کافی نیست و چیزی رزرو نشد؛ می‌توانی محصول‌ها را بدون استخراج با /product ثبت کنی.');
        }

        return new BotReply(
            $text." با تأیید تو {$cap} اعتبار رزرو می‌شود. پس از استخراج موفق فقط مصرف واقعی{$markup} (حداقل {$quote->minimumCredits()} اعتبار) کسر می‌شود، حتی اگر پیشنهادها را نپذیری، و بقیه برمی‌گردد. در صورت خطای کارمند من، شکست، توقف یا لغو چیزی کسر نمی‌شود. این پیش‌فاکتور {$minutes} دقیقه معتبر است.",
            [[['text' => "تأیید و شروع استخراج (حداکثر {$cap} اعتبار)", 'callback_data' => "quote:accept:{$quote->public_id}"]]],
        );
    }
}
