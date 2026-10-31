<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Data\Telegram\BotReply;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The bot's "confirm suggested values" button (ADR-034, FR-032). The button
 * names a revision whose card lists every suggested value; it acts only if
 * that card would show them all, and exactly those values become confirmed
 * in a new revision, keeping the photos they came from. "Without price" confirms the rest and leaves the price unknown. It is
 * never Gate A: the new card still needs its own approve press. A newer
 * revision is never touched. Refusals are answered, never thrown.
 */
final readonly class ConfirmTelegramProductAction
{
    public function __construct(
        private ReviseProductDraftAction $revise,
        private ProductRepositoryInterface $products,
        private ProductDraftNormalizer $normalizer,
        private ProjectSnapshotSigner $signer,
        private TelegramProductCard $card,
    ) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $productPublicId, int $revision, bool $withoutPrice): BotReply
    {
        $product = $this->products->findPublicForWorkspace($workspace, $productPublicId);
        // The revision the card showed: immutable, so reading it outside the lock is safe.
        $shown = $product === null ? null : $this->products->revision($product, $revision);
        if ($product === null || $shown === null || Gate::forUser($user)->denies('update', $product)) {
            return new BotReply('محصول یافت نشد.');
        }
        // Only what that card listed may be confirmed, so a forged button gets nothing more.
        if (! $this->card->confirmable($shown) || ($withoutPrice && ! $this->card->priceSuggested($shown))) {
            return new BotReply('این کارت مقدار پیشنهادی قابل تأییدی ندارد؛ کارت تازه را با /done بگیر.');
        }
        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.');
        }
        RateLimiter::hit($limit, 60);
        // A stored payload that does not match its own MAC is never confirmed into a fresh one.
        if (! hash_equals((string) $shown->payload_mac, $this->signer->payloadMac($workspace, $shown))) {
            return new BotReply('نسخه این محصول قابل اعتماد نیست و از ربات تأیید نمی‌شود.');
        }

        $draft = $this->confirmed($this->normalizer->draftFrom($shown->payload));
        if ($withoutPrice) {
            unset($draft['pricing']);
        }
        try {
            // One key per press: a second press of the same card meets the newer revision and is refused.
            $key = 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48);
            [$confirmed] = $this->revise->execute($workspace, $user, $key, $productPublicId, $revision, $draft);
        } catch (AuthorizationException) {
            return new BotReply('محصول یافت نشد.');
        } catch (DomainException $e) {
            return new BotReply(match ($e->getMessage()) {
                'PRODUCT_NOT_FOUND' => 'محصول یافت نشد.',
                'PRODUCT_REVISION_CONFLICT', 'PROJECT_REOPEN_REQUIRED' => 'این محصول پس از این پیام تغییر کرده است؛ کارت تازه را با /done بگیر.',
                'PRODUCT_ARCHIVED' => 'این محصول رد شده است و تأیید نمی‌شود.',
                'PROJECT_CANCELLED' => 'پروژه این محصول لغو شده است.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از تأیید، شرایط و سیاست داده را با /start بپذیر.',
                'PRODUCT_SKU_TAKEN' => 'SKU پیشنهادی برای محصول دیگری ثبت شده است و از ربات تأیید نمی‌شود.',
                'PRODUCT_ASSET_INVALID' => 'عکس‌های این محصول دیگر در این پروژه نیستند و از ربات تأیید نمی‌شود.',
                'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED' => 'این دکمه دیگر معتبر نیست؛ کارت تازه را با /done بگیر.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                default => 'تأیید انجام نشد؛ دوباره دکمه را بزن.',
            });
        }

        return $this->card->reply($confirmed->product, $confirmed,
            ($withoutPrice ? 'مقدارهای پیشنهادی بدون قیمت تأیید شد: ' : 'مقدارهای پیشنهادی تأیید شد: ')
            .$this->card->summary($confirmed)." (نسخه {$confirmed->revision})."
            // A missing name still blocks Gate A; the card asks for it instead.
            .($this->normalizer->fieldsRequiringConfirmation($confirmed->payload) === [] ? "\nحالا محصول را تأیید کن." : ''),
        );
    }

    /**
     * The draft with every suggested status confirmed and every value unchanged.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function confirmed(array $draft): array
    {
        $confirm = fn (array $field): array => ['status' => $field['status'] === 'suggested' ? 'confirmed' : $field['status']] + $field;
        foreach (['name', 'sku', 'description_fa', 'pricing', 'category_path'] as $field) {
            if (isset($draft[$field])) {
                $draft[$field] = $confirm($draft[$field]);
            }
        }
        $draft['attributes'] = array_map($confirm, $draft['attributes']);

        return $draft;
    }
}
