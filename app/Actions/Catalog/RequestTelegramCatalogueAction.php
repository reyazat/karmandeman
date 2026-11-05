<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Wallet\ReserveCreditsAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Jobs\Export\DeliverCatalogueArtifact;
use App\Jobs\Export\GenerateCatalogueArtifact;
use App\Models\Artifact;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The bot's catalogue button (ADR-052, ADR-053). The first pack of a snapshot,
 * its PDF and one story image per product, holds its fixed price and is made
 * by the export worker; the hold is spent only when the pack is made. A pack
 * already made is sent again for free; only an edit, which ends in a new
 * snapshot, makes and charges a new pack.
 */
final readonly class RequestTelegramCatalogueAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ProjectSnapshotRepositoryInterface $snapshots,
        private ArtifactRepositoryInterface $artifacts,
        private LockActiveWorkspaceAction $lockActive,
        private EnsurePolicyConsentAction $consent,
        private ReserveCreditsAction $reserve,
    ) {}

    public function execute(Workspace $workspace, User $user, string $projectPublicId, int $snapshotNumber): string
    {
        $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId);
        if ($project === null || Gate::forUser($user)->denies('view', $project)) {
            return 'پروژه یافت نشد.';
        }
        // A reopened or cancelled batch has no current approval (ADR-019).
        $snapshot = $project->state->hasApprovedSnapshot() ? $this->snapshots->latestFor($project) : null;
        if ($snapshot === null) {
            return "پروژه «{$project->name}» نسخه نهایی معتبر ندارد؛ پس از تأیید دوباره پروژه کاتالوگ را بگیر.";
        }
        if ($snapshot->number !== $snapshotNumber) {
            return 'پس از این دکمه نسخه نهایی تازه‌تری ثبت شده است؛ دکمه پیام تأیید تازه را بزن.';
        }
        $max = (int) config('karmandeman.catalogue.max_products');
        if (count($snapshot->items) > $max) {
            return "کاتالوگ و استوری برای حداکثر {$max} محصول ساخته می‌شود و این نسخه ".count($snapshot->items).' محصول دارد. محصولات را در پروژه‌های کوچک‌تر ثبت کن؛ اعتباری کسر نشد.';
        }
        $limit = 'telegram-catalogue:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, 5)) {
            return 'فایل‌ها خیلی سریع درخواست شدند؛ یک دقیقه صبر کن.';
        }
        RateLimiter::hit($limit, 60);
        $price = (int) config('karmandeman.credits.catalogue_pack');

        try {
            return DB::transaction(function () use ($workspace, $user, $snapshot, $project, $price): string {
                $this->lockActive->execute($workspace);
                $this->consent->execute($workspace);
                $artifact = $this->artifacts->lockForSnapshot($snapshot, Artifact::KIND_CATALOGUE_PDF);
                if ($artifact?->status === Artifact::STATUS_READY) {
                    DeliverCatalogueArtifact::dispatch($artifact->id, $user->id)->onConnection('telegram')->onQueue('telegram')->beforeCommit();

                    return "کاتالوگ PDF و استوری‌های نسخه {$snapshot->number} پروژه «{$project->name}» دوباره فرستاده می‌شوند؛ هزینه‌ای ندارد.";
                }
                if ($artifact?->status === Artifact::STATUS_QUEUED) {
                    return 'کاتالوگ و استوری‌های این نسخه در حال ساخت‌اند؛ پس از آماده شدن فرستاده می‌شوند.';
                }
                if ($artifact?->generated_at !== null) {
                    // Made and paid for once, then failed to be made again: never charged twice.
                    $this->artifacts->requeuePaid($artifact, $user->id);
                    GenerateCatalogueArtifact::dispatch($artifact->id)->onConnection('export')->onQueue('export')->beforeCommit();

                    return "کاتالوگ و استوری‌های نسخه {$snapshot->number} پروژه «{$project->name}» دوباره ساخته می‌شوند؛ هزینه تازه‌ای ندارد.";
                }
                if ($artifact === null) {
                    $artifact = $this->artifacts->create($snapshot, $user, Artifact::KIND_CATALOGUE_PDF, $price);
                } else {
                    $this->artifacts->requeue($artifact, $user, $price);
                }
                // A new key per request: a second press finds the file queued above and holds nothing.
                $hold = $this->reserve->execute(
                    $workspace, $price, 'credit:reserve:artifact:'.$artifact->id.':'.Str::uuid()->toString(),
                    CreditLedgerEntry::RELATED_ARTIFACT, $artifact->id, Artifact::KIND_CATALOGUE_PDF,
                );
                $this->artifacts->attachHold($artifact, $hold);
                // In the webhook transaction like the outbox (ADR-008): the hold and the job commit together.
                GenerateCatalogueArtifact::dispatch($artifact->id)->onConnection('export')->onQueue('export')->beforeCommit();

                return "ساخت کاتالوگ PDF و استوری هر محصول نسخه {$snapshot->number} پروژه «{$project->name}» شروع شد. {$price} اعتبار رزرو شد و فقط اگر فایل‌ها ساخته شوند کسر می‌شود؛ دریافت دوباره همین فایل‌ها رایگان است.";
            });
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'INSUFFICIENT_CREDITS' => "برای ساخت کاتالوگ و استوری‌ها {$price} اعتبار لازم است و اعتبار قابل استفاده کافی نیست. موجودی را با /balance ببین.",
                'POLICY_CONSENT_REQUIRED' => 'پیش از ساخت کاتالوگ، شرایط و سیاست داده را با /start بپذیر.',
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => 'حساب در حال حذف است و فایل تازه‌ای ساخته نمی‌شود.',
                default => 'درخواست کاتالوگ انجام نشد؛ دوباره دکمه را بزن.',
            };
        }
    }
}
