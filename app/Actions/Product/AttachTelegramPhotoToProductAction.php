<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Project\AttachProjectAssetAction;
use App\Actions\Project\ReopenForCorrectionAction;
use App\Data\Telegram\BotReply;
use App\Enums\ProductStatus;
use App\Models\Asset;
use App\Models\Product;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A photo the seller sent in reply to a product card (ADR-041): stored in that
 * product's batch and added to the revision the card showed, as a new
 * revision for Gate A. An approved batch is reopened first. All of it is one
 * transaction: nothing is reopened or kept when the photo cannot be added. A
 * retried job replays each step by its key instead of calling the card old.
 */
final readonly class AttachTelegramPhotoToProductAction
{
    private const MAX_IMAGES = 10;

    public function __construct(
        private ProductRepositoryInterface $products,
        private AssetRepositoryInterface $assets,
        private AttachProjectAssetAction $attach,
        private ReviseProductDraftAction $revise,
        private ReopenForCorrectionAction $reopen,
        private ProductDraftNormalizer $normalizer,
        private ProjectSnapshotSigner $signer,
        private TelegramProductCard $card,
        private AiWorkflowRunRepositoryInterface $runs,
    ) {}

    public function execute(TelegramMediaIntake $intake, Project $project, #[\SensitiveParameter] string $bytes): BotReply|string|null
    {
        $workspace = $intake->workspace;
        $key = 'telegram-'.substr($intake->message_key, 0, 48);
        $product = $intake->target_product_id === null ? null : $this->products->findForWorkspace($workspace, $intake->target_product_id);
        $shown = $product === null || $intake->target_revision === null ? null : $this->products->revision($product, $intake->target_revision);
        if ($product === null || $shown === null) {
            return 'محصول این کارت یافت نشد.';
        }
        if ($product->status === ProductStatus::Archived) {
            return 'این محصول رد شده است و عکس تازه نمی‌گیرد.';
        }
        $images = $shown->payload['image_asset_ids'];
        if (count($images) >= self::MAX_IMAGES) {
            return 'این محصول '.self::MAX_IMAGES.' عکس دارد و عکس دیگری نمی‌گیرد.';
        }
        // The other fields are copied into a revision with a fresh MAC (ADR-029).
        if (! hash_equals((string) $shown->payload_mac, $this->signer->payloadMac($workspace, $shown))) {
            return 'نسخه این محصول قابل اعتماد نیست و از ربات اصلاح نمی‌شود.';
        }

        try {
            return DB::transaction(function () use ($intake, $project, $bytes, $workspace, $key, $product, $shown, $images): BotReply {
                [$project, $notice] = $this->reopen->execute($workspace, $intake->user, "{$key}-reopen", $project);
                /** @var Asset $asset */
                [$asset] = $this->attach->execute($workspace, $intake->user, $key, $project->public_id, $bytes, Asset::SOURCE_TELEGRAM, $intake->message_key);
                if (in_array($asset->public_id, $images, true)) {
                    // Nothing changed: the reopen, if any, rolls back with this answer.
                    throw new DomainException('PHOTO_ALREADY_ON_PRODUCT');
                }
                // The same bytes as a photo another product holds, or one a run is reading, are
                // that photo: it never moves silently to this product (ADR-025, ADR-032).
                if (($asset->claimed_by_product_id !== null && $asset->claimed_by_product_id !== $product->id)
                    || in_array($asset->public_id, $this->runs->openForProject($project)->input_asset_ids ?? [], true)
                    || $this->citedElsewhere($workspace, $project, $product, $asset)) {
                    throw new DomainException('PHOTO_USED_ELSEWHERE');
                }
                $draft = $this->normalizer->draftFrom($shown->payload);
                $draft['image_asset_ids'] = [...$images, $asset->public_id];
                [$revised] = $this->revise->execute($workspace, $intake->user, "{$key}-revise", $product->public_id, $shown->revision, $draft);
                $this->assets->claim([$asset], $product);

                return $this->card->reply($product, $revised,
                    ($notice ?? '').'عکس به محصول '.$this->card->summary($revised)." اضافه شد (نسخه {$revised->revision}).\nنسخه تازه را تأیید کن؛ تأیید قبلی برای آن معتبر نیست.",
                );
            });
        } catch (AuthorizationException) {
            return null;
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PHOTO_ALREADY_ON_PRODUCT' => 'این عکس قبلاً برای همین محصول ثبت شده بود.',
                'PHOTO_USED_ELSEWHERE' => 'این عکس برای محصول دیگری ثبت شده یا در حال استخراج است و به این محصول اضافه نشد.',
                'PRODUCT_SKU_TAKEN' => 'کد (SKU) این محصول برای محصول دیگری ثبت شده است و از ربات اصلاح نمی‌شود.',
                'ASSET_UNSUPPORTED' => 'فقط عکس JPEG، PNG یا WebP پذیرفته می‌شود.',
                'ASSET_TOO_LARGE' => 'ابعاد این عکس بیش از حد مجاز است.',
                'ASSET_DAMAGED' => 'این عکس خراب است یا کامل نرسیده است؛ دوباره بفرست.',
                'PROJECT_PAST_APPROVAL' => "پروژه «{$project->name}» از مرحله تأیید گذشته است و محصولش اصلاح نمی‌شود.",
                'PRODUCT_REVISION_CONFLICT', 'PROJECT_VERSION_CONFLICT', 'PRODUCT_NOT_FOUND' => 'این محصول پس از این کارت تغییر کرده است؛ عکس را در پاسخ به کارت نسخه تازه بفرست.',
                'PROJECT_CANCELLED', 'PROJECT_NOT_ACCEPTING_INPUT' => "پروژه «{$project->name}» دیگر عکس نمی‌گیرد.",
                'POLICY_CONSENT_REQUIRED' => 'پیش از ارسال عکس، شرایط و سیاست داده را با /start بپذیر.',
                // Erasure or suspension: the account gets no new message.
                'ACCOUNT_DELETION_PENDING', 'WORKSPACE_NOT_ACTIVE' => null,
                default => 'افزودن عکس به محصول انجام نشد؛ دوباره بفرست.',
            };
        }
    }

    /** A photo the current revision of another active product of the batch cites (ADR-023). */
    private function citedElsewhere(Workspace $workspace, Project $project, Product $product, Asset $asset): bool
    {
        return in_array($asset->public_id, $this->products->citedImageIds($workspace, $project, $product), true);
    }
}
