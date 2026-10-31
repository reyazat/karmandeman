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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The buttons of the bot's grouped price question (ADR-035): confirm the
 * listed prices, drop them all for now, or review the products one card at
 * a time. Each press is its own key, so a later press re-runs every check.
 * Refusals are answered, never thrown, so the webhook commits its ack.
 */
final readonly class AnswerTelegramPriceQuestionAction
{
    public function __construct(
        private ResolveSuggestedPricesAction $resolve,
        private ProjectRepositoryInterface $projects,
        private TelegramBatchReview $review,
    ) {}

    /**
     * @param  'prices'|'noprices'|'cards'  $answer
     * @return list<BotReply>
     */
    public function execute(Workspace $workspace, User $user, string $callbackId, string $projectPublicId, int $version, string $answer): array
    {
        if ($answer === 'cards') {
            $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId);
            if ($project === null || Gate::forUser($user)->denies('view', $project)) {
                return [new BotReply('پروژه یافت نشد.')];
            }
            if ($project->state !== ProjectState::ReviewRequired) {
                return [new BotReply("پروژه «{$project->name}» دیگر در مرحله بررسی نیست؛ /done را بفرست.")];
            }
            $cards = $this->review->cards($workspace, $project);

            return $cards === [] ? $this->review->replies($workspace, $project) : $cards;
        }

        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return [new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.')];
        }
        RateLimiter::hit($limit, 60);
        $confirm = $answer === 'prices';
        try {
            [$project] = $this->resolve->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $projectPublicId, $version, $confirm,
            );
        } catch (AuthorizationException) {
            return [new BotReply('پروژه یافت نشد.')];
        } catch (DomainException $e) {
            return [new BotReply(match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => 'پروژه یافت نشد.',
                // A retry of the same answer can never pass these, so none asks to press again.
                'PROJECT_VERSION_CONFLICT', 'PROJECT_STATE_CONFLICT', 'PRODUCT_REVISION_CONFLICT', 'PROJECT_REOPEN_REQUIRED',
                'PRODUCT_ARCHIVED', 'PROJECT_CANCELLED' => 'پروژه پس از این پیام تغییر کرده است و قیمتی تغییر نکرد؛ /done را دوباره بفرست.',
                'PRODUCT_PAYLOAD_UNTRUSTED' => 'نسخه یکی از این محصولات قابل اعتماد نیست و قیمتی تغییر نکرد؛ کارت‌ها را تک‌به‌تک بررسی کن.',
                'PRODUCT_SKU_TAKEN' => 'SKU یکی از این محصولات برای محصول دیگری ثبت شده است و قیمتی تغییر نکرد؛ کارت‌ها را تک‌به‌تک بررسی کن.',
                'PRODUCT_ASSET_INVALID' => 'عکس یکی از این محصولات دیگر در این پروژه نیست و قیمتی تغییر نکرد؛ کارت‌ها را تک‌به‌تک بررسی کن.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از تأیید، شرایط و سیاست داده را با /start بپذیر.',
                'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED' => 'این دکمه دیگر معتبر نیست؛ /done را دوباره بفرست.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                default => 'پاسخ ثبت نشد؛ دوباره دکمه را بزن.',
            })];
        }

        return [
            new BotReply($confirm
                ? 'قیمت‌های پیشنهادی فهرست تأیید شدند. بقیه مقدارهای پیشنهادی هر محصول روی کارتش می‌مانند.'
                : 'محصولات فهرست فعلاً بدون قیمت ماندند. قیمت را بعداً با پاسخ به کارت هر محصول بنویس.'),
            ...$this->review->replies($workspace, $project),
        ];
    }
}
