<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\ProductTableRejected;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The buttons under a CSV mapping in the bot (ADR-040): import with the unit
 * the file names or the one pressed, or drop the file. Through the API's
 * actions; a press repeated by Telegram replays. Refusals are answered,
 * never thrown.
 */
final readonly class ImportTelegramProductTableAction
{
    public function __construct(
        private ImportProductTableAction $import,
        private DiscardProductTableAction $discard,
    ) {}

    /** @param  'go'|'irt'|'irr'|'drop'  $verb */
    public function execute(Workspace $workspace, User $user, string $callbackId, string $verb, string $tablePublicId): string
    {
        try {
            if ($verb === 'drop') {
                $this->discard->execute($workspace, $user, $tablePublicId);

                return 'فایل کنار گذاشته شد و چیزی از آن ثبت نشد.';
            }
            [$table] = $this->import->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $tablePublicId,
                match ($verb) {
                    'irt' => 'IRT',
                    'irr' => 'IRR',
                    default => null,
                },
            );
        } catch (AuthorizationException) {
            return 'فایل یافت نشد.';
        } catch (ProductTableRejected $e) {
            return 'کد محصول ردیف‌های '.implode('، ', array_slice($e->lines, 0, 10)).' قبلاً در فضای کاری ثبت شده است؛ هیچ محصولی از این فایل ثبت نشد. کدها را اصلاح کن و فایل را دوباره بفرست.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'TABLE_NOT_FOUND' => 'فایل یافت نشد؛ شاید قبلاً کنار گذاشته شده است.',
                'TABLE_ALREADY_IMPORTED' => 'محصولات این فایل قبلاً ثبت شده‌اند.',
                'TABLE_PRICE_UNIT_REQUIRED' => 'واحد قیمت‌ها مشخص نیست؛ یکی از دکمه‌های تومان یا ریال را بزن.',
                'TABLE_PRICE_UNIT_CONFLICT' => 'واحد انتخاب‌شده با واحدی که فایل نام می‌برد یکی نیست.',
                'TABLE_HAS_NO_PRODUCTS' => 'هیچ ردیف قابل ثبتی در فایل نیست.',
                'PROJECT_NOT_ACCEPTING_PRODUCTS' => 'این پروژه دیگر محصول تازه نمی‌پذیرد.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                default => 'ثبت محصولات فایل انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return "{$table->product_count} محصول از فایل در پیش‌نویس ثبت شد. برای بررسی و تأیید هر محصول /done را بفرست.";
    }
}
