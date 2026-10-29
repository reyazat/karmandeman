<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The confirm button under `/cancel` (ADR-031): cancels exactly the batch
 * version the offer showed, through the API's CancelProjectAction (FR-063,
 * ADR-019). Refusals are answered, never thrown.
 */
final readonly class CancelTelegramProjectAction
{
    public function __construct(private CancelProjectAction $cancel) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $projectPublicId, int $version): string
    {
        try {
            [$project] = $this->cancel->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $projectPublicId, $version,
            );
        } catch (AuthorizationException) {
            return 'پروژه یافت نشد.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => 'پروژه یافت نشد.',
                'PROJECT_VERSION_CONFLICT' => 'پروژه پس از این پیام تغییر کرده است و لغو نشد؛ اگر هنوز می‌خواهی، /cancel را دوباره بفرست.',
                'PROJECT_STATE_CONFLICT' => 'این پروژه دیگر قابل لغو نیست.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                default => 'لغو پروژه انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return "پروژه «{$project->name}» لغو شد. با /projects پروژه دیگری انتخاب کن یا با /new پروژه تازه بساز.";
    }
}
