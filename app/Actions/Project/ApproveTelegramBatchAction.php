<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\ProjectProductsNotApproved;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Batch approval from the bot's button (ADR-027): freezes the signed snapshot
 * of exactly the batch version /done showed. Each press is its own key, so a
 * later press re-runs every check. Refusals are answered, never thrown.
 */
final readonly class ApproveTelegramBatchAction
{
    public function __construct(private ApproveProjectProductsAction $approve) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $projectPublicId, int $version): string
    {
        try {
            [$snapshot] = $this->approve->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $projectPublicId, $version,
            );
        } catch (ProjectProductsNotApproved $e) {
            return count($e->products).' محصول هنوز تأیید نشده‌اند؛ /done را بفرست تا دکمه‌های تأییدشان بیاید.';
        } catch (ProductIntegrityFailed) {
            return 'نسخه تأییدشده یکی از محصولات قابل اعتماد نیست و پروژه تأیید نشد.';
        } catch (AuthorizationException) {
            return 'پروژه یافت نشد.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => 'پروژه یافت نشد.',
                'PROJECT_VERSION_CONFLICT' => 'پروژه پس از این پیام تغییر کرده است؛ /done را دوباره بفرست.',
                'PROJECT_STATE_CONFLICT' => 'این پروژه در وضعیت تأیید نیست؛ /done را بفرست.',
                'PROJECT_HAS_NO_PRODUCTS' => 'این پروژه محصول فعالی ندارد و تأیید نمی‌شود.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از تأیید، شرایط و سیاست داده را با /start بپذیر.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                default => 'تأیید پروژه انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return 'پروژه تأیید شد: '.count($snapshot->items)." محصول در نسخه نهایی شماره {$snapshot->number} ثبت شد.\n"
            .'خروجی JSON این نسخه از API گرفته می‌شود؛ فایل از ربات هنوز ارسال نمی‌شود.';
    }
}
