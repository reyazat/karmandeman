<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Exceptions\ProductRequiresConfirmation;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Gate A from the bot's "approve" button (ADR-026). The button names the exact
 * revision the seller was shown; a newer revision is never approved by it.
 * Every refusal is answered, never thrown, so the webhook commits its ack.
 */
final readonly class ApproveTelegramProductAction
{
    public function __construct(private ApproveProductRevisionAction $approve) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $productPublicId, int $revision): string
    {
        try {
            // One key per press, not per revision: a later press re-runs every check, so a
            // button pressed after a revise, reject or cancel never answers "approved" again.
            // A second press on an unchanged product approves nothing new (Gate A action).
            $key = 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48);
            [$approved] = $this->approve->execute($workspace, $user, $key, $productPublicId, $revision);
        } catch (ProductRequiresConfirmation) {
            return 'این محصول هنوز بخشی تأییدنشده دارد و از ربات تأیید نمی‌شود.';
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
                default => 'تأیید انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return "محصول «{$approved->payload['name']['value']}» تأیید شد (نسخه {$approved->revision}).";
    }
}
