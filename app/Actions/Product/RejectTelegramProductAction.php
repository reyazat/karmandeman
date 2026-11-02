<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Project\ReopenForCorrectionAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Enums\ProductStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The bot's "reject" button (ADR-028): archives the product, but only while its
 * current revision is the one the card showed. Its photos return to the next
 * /product (ADR-026). In an approved batch the reject reopens it first, like a
 * correction from the card (ADR-041, ADR-044). Refusals are answered, never thrown.
 */
final readonly class RejectTelegramProductAction
{
    public function __construct(
        private ArchiveProductAction $archive,
        private ProductRepositoryInterface $products,
        private ProjectRepositoryInterface $projects,
        private ReopenForCorrectionAction $reopen,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    public function execute(Workspace $workspace, User $user, string $callbackId, string $productPublicId, int $revision): string
    {
        $key = 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48);
        $project = null;
        try {
            [$current, $notice] = DB::transaction(function () use ($workspace, $user, $key, $productPublicId, $revision, &$project): array {
                // Decided under the workspace lock, on the state the reject acts on; an erasure
                // request is answered as such (FR-086). The archive and the reopen authorize.
                $this->lockActive->execute($workspace);
                $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
                    ?? throw new DomainException('PRODUCT_NOT_FOUND');
                // An old card of a product rejected before approval must not reopen the batch.
                if ($product->status === ProductStatus::Archived) {
                    throw new DomainException('PRODUCT_ARCHIVED');
                }
                $project = $product->project_id === null ? null : $this->projects->findForWorkspace($workspace, $product->project_id);
                $notice = null;
                // An outdated card fails the revision check and reopens nothing.
                if ($project !== null && $revision === $product->current_revision) {
                    [, $notice] = $this->reopen->execute($workspace, $user, "{$key}-reopen", $project);
                }
                [$current] = $this->archive->execute($workspace, $user, $key, $productPublicId, $revision);

                return [$current, $notice];
            });
        } catch (AuthorizationException) {
            return 'محصول یافت نشد.';
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PRODUCT_NOT_FOUND' => 'محصول یافت نشد.',
                'PRODUCT_ARCHIVED' => 'این محصول قبلاً رد شده است.',
                'PRODUCT_REVISION_CONFLICT', 'PROJECT_REOPEN_REQUIRED' => 'این محصول پس از این پیام تغییر کرده است؛ نسخه تازه را بررسی کن.',
                'PROJECT_PAST_APPROVAL' => "پروژه «{$project?->name}» از مرحله تأیید گذشته است و محصولش رد نمی‌شود.",
                'PROJECT_VERSION_CONFLICT' => 'پروژه همین حالا تغییر کرد؛ دوباره دکمه را بزن.',
                'PROJECT_STATE_CONFLICT' => 'پروژه این محصول دیگر ورودی نمی‌گیرد و محصول از ربات رد نمی‌شود.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                // The workspace lock refused it: erasure started after the webhook's check (FR-086).
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.',
                default => 'رد محصول انجام نشد؛ دوباره دکمه را بزن.',
            };
        }

        return ($notice ?? '')."محصول «{$current->payload['name']['value']}» رد شد؛ عکس‌هایش برای /product بعدی آزاد شدند.";
    }
}
