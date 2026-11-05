<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Wallet\EnsureInputCreditAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\BotReply;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Telegram\TelegramInputGuide;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A seller's text sent in reply to their own photo becomes that photo's text
 * for the next extraction run (ADR-050). Only a photo no run or product has
 * used yet takes it; text never attaches to a guessed product.
 */
final readonly class AddTelegramPhotoTextAction
{
    public function __construct(
        private LockActiveWorkspaceAction $lockActive,
        private EnsurePolicyConsentAction $consent,
        private EnsureInputCreditAction $credit,
        private AssetRepositoryInterface $assets,
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private AiWorkflowRunRepositoryInterface $runs,
    ) {}

    /**
     * @param  string  $photoMessageKey  subject key of the photo message replied to
     * @return BotReply|string|null the reply, or null when that message is no photo of this account
     */
    public function execute(Workspace $workspace, User $user, string $photoMessageKey, #[\SensitiveParameter] string $text): BotReply|string|null
    {
        return DB::transaction(function () use ($workspace, $user, $photoMessageKey, $text): BotReply|string|null {
            $this->lockActive->execute($workspace);
            $asset = $this->assets->lockBySourceMessage($workspace, $photoMessageKey);
            if ($asset === null) {
                // Appending to a queued intake could race its import and be lost; ask again instead.
                return $this->intakes->pendingForMessage($workspace, $photoMessageKey)
                    ? 'این عکس هنوز در حال ثبت است؛ پس از پیام «عکس اضافه شد» دوباره روی آن Reply کن.'
                    : null;
            }
            try {
                $this->consent->execute($workspace);
                $this->credit->execute($workspace);
            } catch (DomainException $e) {
                return $e->getMessage() === 'CREDIT_REQUIRED'
                    ? 'اعتبار قابل استفاده‌ات تمام شده است و پیام تازه پذیرفته نمی‌شود. موجودی را با /balance ببین و برای افزایش اعتبار /support را بفرست.'
                    : 'پیش از ارسال مشخصات، شرایط و سیاست داده را با /start بپذیر.';
            }
            $project = $this->projects->findForWorkspace($workspace, $asset->project_id);
            if ($project === null) {
                return null;
            }
            Gate::forUser($user)->authorize('update', $project);
            if (! $project->state->isOpenForInput()) {
                return "پروژه «{$project->name}» دیگر ورودی نمی‌پذیرد.";
            }
            if ($this->used($workspace, $project, $asset)) {
                return 'این عکس قبلاً خوانده شده یا به محصولی وصل است؛ برای اصلاح، روی کارت همان محصول Reply کن.';
            }
            $combined = $asset->seller_text === null ? $text : $asset->seller_text."\n".$text;
            if (mb_strlen($combined) > Asset::SELLER_TEXT_MAX) {
                return 'متن این عکس از '.Asset::SELLER_TEXT_MAX.' نویسه بیشتر می‌شود؛ کوتاه‌تر بنویس.';
            }
            $this->assets->updateSellerText($asset, $combined);
            // The run input changed: an earlier quote no longer prices it (ADR-039).
            $this->projects->bumpVersion($workspace, $project->id);

            return TelegramInputGuide::afterInput("مشخصات برای همین عکس در پروژه «{$project->name}» ثبت شد.");
        });
    }

    /** Read by a run (succeeded or still open), claimed by a bot product or cited by an API product. */
    private function used(Workspace $workspace, Project $project, Asset $asset): bool
    {
        if ($asset->claimed_by_product_id !== null) {
            return true;
        }
        $open = $this->runs->openForProject($project);

        return in_array($asset->public_id, [
            ...$this->runs->extractedAssetIds($project),
            ...($open->input_asset_ids ?? []),
            ...$this->products->citedImageIds($workspace, $project),
        ], true);
    }
}
