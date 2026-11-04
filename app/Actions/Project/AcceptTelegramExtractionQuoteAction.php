<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The accept button under a `/done` quote (FR-072, ADR-039): finishes the
 * batch input with exactly that quote, through the API's finish-input, so the
 * credit is held before the run starts. Refusals are answered, never thrown.
 */
final readonly class AcceptTelegramExtractionQuoteAction
{
    public function __construct(
        private CreditQuoteRepositoryInterface $quotes,
        private ProjectRepositoryInterface $projects,
        private FinishProjectInputAction $finish,
    ) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $quotePublicId): BotReply
    {
        $quote = $this->quotes->findPublicForWorkspace($workspace, $quotePublicId);
        $project = $quote === null ? null : $this->projects->findForWorkspace($workspace, $quote->project_id);
        if ($quote === null || $project === null) {
            return new BotReply('پیش‌فاکتور یافت نشد.');
        }
        try {
            [$project] = $this->finish->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $project->public_id, $project->version, $quote->public_id,
            );
        } catch (AuthorizationException) {
            return new BotReply('پیش‌فاکتور یافت نشد.');
        } catch (DomainException $e) {
            return new BotReply(match ($e->getMessage()) {
                'QUOTE_NOT_FOUND', 'PROJECT_NOT_FOUND' => 'پیش‌فاکتور یافت نشد.',
                'QUOTE_ALREADY_USED' => 'این پیش‌فاکتور قبلاً تأیید شده است.',
                'QUOTE_EXPIRED' => 'این پیش‌فاکتور منقضی شده است؛ برای پیش‌فاکتور تازه /done را دوباره بفرست.',
                'QUOTE_STALE', 'PROJECT_VERSION_CONFLICT' => "پروژه «{$project->name}» پس از این پیش‌فاکتور تغییر کرده است؛ برای پیش‌فاکتور تازه /done را دوباره بفرست.",
                'PROJECT_STATE_CONFLICT' => "پروژه «{$project->name}» دیگر منتظر پایان ورودی نیست.",
                'PROJECT_HAS_NO_PRODUCTS' => "پروژه «{$project->name}» هنوز محصولی ندارد؛ اول با /product محصول ثبت کن.",
                'INSUFFICIENT_CREDITS' => 'اعتبار کافی برای این استخراج نداری و چیزی رزرو نشد؛ می‌توانی محصول‌ها را با /product ثبت کنی.',
                'EXTRACTION_PHOTOS_TOO_LARGE' => 'حجم عکس‌های این پروژه برای یک استخراج بیش از حد است؛ عکس‌های کم‌حجم‌تر را در پروژه تازه‌ای با /new بفرست یا محصول را با /product ثبت کن.',
                'EXTRACTION_TOO_MANY_PHOTOS' => 'عکس‌های این پروژه برای یک استخراج بیش از '.config('karmandeman.ai.max_assets_per_run').' تاست؛ پروژه تازه‌ای با /new بساز.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                default => 'شروع استخراج انجام نشد؛ دوباره دکمه را بزن.',
            });
        }

        // A fixed-price quote of ADR-039 is spent whole; a usage quote spends what the run used.
        $charge = $quote->minimumCredits() === null
            ? 'این مقدار فقط پس از استخراج موفق کسر می‌شود.'
            : 'فقط پس از استخراج موفق مصرف واقعی'.($quote->markupPercent() > 0 ? " به‌علاوه {$quote->markupPercent()} درصد کارمزد کارمند من" : '')." (حداقل {$quote->minimumCredits()} اعتبار) کسر می‌شود و بقیه برمی‌گردد.";

        return new BotReply("{$quote->capCredits()} اعتبار برای استخراج پروژه «{$project->name}» رزرو شد. محصولات از عکس‌ها استخراج می‌شوند و پس از پایان خبر می‌دهم؛ همه مقدارها پیشنهادی خواهند بود. {$charge}");
    }
}
