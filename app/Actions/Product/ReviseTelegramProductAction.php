<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use App\Services\Telegram\TelegramProductCard;
use App\Services\Telegram\TelegramProductText;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Correction from the bot (ADR-029): the seller replies to a product card with
 * the name and, optionally, a new price. The card names the revision corrected,
 * so a newer revision is never overwritten. Other fields and the photos stay.
 * In an approved batch the answer offers reopen instead. Refusals are answered.
 */
final readonly class ReviseTelegramProductAction
{
    public function __construct(
        private ReviseProductDraftAction $revise,
        private ProductRepositoryInterface $products,
        private ProjectRepositoryInterface $projects,
        private ProductDraftNormalizer $normalizer,
        private ProjectSnapshotSigner $signer,
        private TelegramProductText $productText,
        private TelegramProductCard $card,
    ) {}

    public function execute(Workspace $workspace, User $user, string $messageKey, string $productPublicId, int $revision, string $input): BotReply
    {
        $parsed = $this->productText->parse($input, "برای اصلاح، نام محصول را در خط اول و قیمت تازه را (اختیاری) با واحد در خط دوم بنویس:\nکیف چرم مشکی\n۱۲۰٬۰۰۰ تومان\nبدون خط دوم، قیمت قبلی می‌ماند.");
        if (is_string($parsed)) {
            return new BotReply($parsed);
        }
        $product = $this->products->findPublicForWorkspace($workspace, $productPublicId);
        // The revision the card showed: immutable, so reading it outside the lock is safe.
        $shown = $product === null ? null : $this->products->revision($product, $revision);
        if ($product === null || $shown === null || Gate::forUser($user)->denies('update', $product)) {
            return new BotReply('محصول یافت نشد.');
        }
        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.');
        }
        RateLimiter::hit($limit, 60);

        // The other fields are copied into a revision that gets a fresh MAC: a stored payload
        // that does not match its own MAC is never laundered through a correction.
        if (! hash_equals((string) $shown->payload_mac, $this->signer->payloadMac($workspace, $shown))) {
            return new BotReply('نسخه این محصول قابل اعتماد نیست و از ربات اصلاح نمی‌شود.');
        }
        $draft = $this->normalizer->draftFrom($shown->payload);
        $draft['name'] = ['value' => $parsed['name'], 'status' => 'confirmed'];
        if ($parsed['price'] !== null) {
            $draft['pricing'] = ['regular_price' => $parsed['price'], 'input_unit' => $parsed['unit'], 'status' => 'confirmed'];
        }
        try {
            [$revised] = $this->revise->execute($workspace, $user, 'telegram-'.substr($messageKey, 0, 48), $productPublicId, $revision, $draft);
        } catch (AuthorizationException) {
            return new BotReply('محصول یافت نشد.');
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PROJECT_REOPEN_REQUIRED' => $this->reopenOffer($workspace, $product->project_id),
                'PRODUCT_NOT_FOUND' => new BotReply('محصول یافت نشد.'),
                'PRODUCT_REVISION_CONFLICT' => new BotReply('این محصول پس از این پیام تغییر کرده است؛ به کارت نسخه تازه پاسخ بده.'),
                'PRODUCT_ARCHIVED' => new BotReply('این محصول رد شده است و اصلاح نمی‌شود.'),
                'PROJECT_CANCELLED' => new BotReply('پروژه این محصول لغو شده است.'),
                'POLICY_CONSENT_REQUIRED' => new BotReply('پیش از اصلاح، شرایط و سیاست داده را با /start بپذیر.'),
                'PRODUCT_SKU_TAKEN' => new BotReply('SKU این محصول برای محصول دیگری ثبت شده است و از ربات اصلاح نمی‌شود.'),
                // Never thrown: a rollback would also drop the reply.
                default => new BotReply('اصلاح انجام نشد؛ دوباره پاسخ بده.'),
            };
        }

        return $this->card->reply($revised->product, $revised,
            'محصول اصلاح شد: '.$this->card->summary($revised)." (نسخه {$revised->revision}).\nنسخه تازه را تأیید کن؛ تأیید قبلی برای آن معتبر نیست.",
        );
    }

    private function reopenOffer(Workspace $workspace, ?int $projectId): BotReply
    {
        $project = $projectId === null ? null : $this->projects->findForWorkspace($workspace, $projectId);
        if ($project === null) {
            return new BotReply('پروژه یافت نشد.');
        }
        // Later states hold a quote or credit and are not reopened yet (ReopenProjectAction).
        if ($project->state !== ProjectState::ProductApproved) {
            return new BotReply("پروژه «{$project->name}» از مرحله تأیید گذشته است و محصولش اصلاح نمی‌شود.");
        }

        return new BotReply(
            "پروژه «{$project->name}» تأیید شده است و محصولش بدون بازگشایی اصلاح نمی‌شود.\n"
            .'با بازگشایی، خروجی نسخه نهایی فعلی تا تأیید دوباره پروژه در دسترس نیست.',
            [[['text' => 'بازگشایی پروژه برای اصلاح', 'callback_data' => "project:reopen:{$project->public_id}:{$project->version}"]]],
        );
    }
}
