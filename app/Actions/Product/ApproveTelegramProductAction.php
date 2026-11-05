<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Exceptions\ProductRequiresConfirmation;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Gate A from the bot's "approve" button (ADR-026). The button names the exact
 * revision the seller was shown; a newer revision is never approved by it.
 * Every refusal is answered, never thrown, so the webhook commits its ack.
 */
final readonly class ApproveTelegramProductAction
{
    public function __construct(
        private ApproveProductRevisionAction $approve,
        private ProductRepositoryInterface $products,
        private TelegramProductCard $card,
    ) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $productPublicId, int $revision): string
    {
        $product = $this->products->findPublicForWorkspace($workspace, $productPublicId);
        // The revision the card showed: immutable, so reading it outside the lock is safe.
        $shown = $product === null ? null : $this->products->revision($product, $revision);
        // A card too long to show every value has no approve button; a forged one approves nothing (ADR-051).
        // Every other refusal, such as a missing product or a workspace being erased, is Gate A's answer.
        if ($shown !== null && Gate::forUser($user)->allows('update', $product) && ! $this->card->approvable($shown)) {
            return 'جزئیات این محصول برای نمایش در ربات بیش از حد طولانی‌اند و از ربات تأیید نمی‌شود؛ با پاسخ به کارت، متن را کوتاه کن.';
        }
        try {
            // One key per press, not per revision: a later press re-runs every check, so a
            // button pressed after a revise, reject or cancel never answers "approved" again.
            // A second press on an unchanged product approves nothing new (Gate A action).
            $key = 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48);
            [$approved] = $this->approve->execute($workspace, $user, $key, $productPublicId, $revision);
        } catch (ProductRequiresConfirmation) {
            return 'این محصول هنوز مقدار پیشنهادیِ تأییدنشده یا نام خالی دارد؛ کارتش را با /done بگیر و اول مقدارها را تأیید کن.';
        } catch (AuthorizationException) {
            return 'محصول یافت نشد.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PRODUCT_NOT_FOUND' => 'محصول یافت نشد.',
                'PRODUCT_REVISION_CONFLICT' => 'این محصول پس از این پیام تغییر کرده است؛ نسخه تازه را بررسی کن.',
                'PRODUCT_ARCHIVED' => 'این محصول رد شده است و تأیید نمی‌شود.',
                'PROJECT_CANCELLED' => 'پروژه این محصول لغو شده است.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از تأیید، شرایط و سیاست داده را با /start بپذیر.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                // The workspace lock refused it: erasure started after the webhook's check (FR-086).
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                default => 'تأیید انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return "محصول «{$approved->payload['name']['value']}» تأیید شد (نسخه {$approved->revision}).";
    }
}
