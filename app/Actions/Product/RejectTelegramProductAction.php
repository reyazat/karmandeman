<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The bot's "reject" button (ADR-028): archives the product, but only while its
 * current revision is the one the card showed. Its photos return to the next
 * /product (ADR-026). Refusals are answered, never thrown.
 */
final readonly class RejectTelegramProductAction
{
    public function __construct(private ArchiveProductAction $archive) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $productPublicId, int $revision): string
    {
        try {
            [$current] = $this->archive->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $productPublicId, $revision,
            );
        } catch (AuthorizationException) {
            return 'محصول یافت نشد.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PRODUCT_NOT_FOUND' => 'محصول یافت نشد.',
                'PRODUCT_REVISION_CONFLICT' => 'این محصول پس از این پیام تغییر کرده است؛ نسخه تازه را بررسی کن.',
                'PROJECT_STATE_CONFLICT' => 'پروژه این محصول دیگر در حال بررسی نیست و محصول از ربات رد نمی‌شود.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                default => 'رد محصول انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return "محصول «{$current->payload['name']['value']}» رد شد؛ عکس‌هایش برای /product بعدی آزاد شدند.";
    }
}
