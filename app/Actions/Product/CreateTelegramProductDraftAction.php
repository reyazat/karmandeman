<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Project\ResolveTelegramInputProjectAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\BotReply;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\Telegram\TelegramProductCard;
use App\Services\Telegram\TelegramProductText;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `/product` in the bot (ADR-025, ADR-041): the seller types the name on the first line
 * and, optionally, the price with its unit on the second. No AI reads the text. Photos sent
 * to the batch since the previous /product are attached, at most ten.
 * Runs inside the webhook transaction, under the workspace lock.
 */
final readonly class CreateTelegramProductDraftAction
{
    private const MAX_IMAGES = 10;

    public function __construct(
        private EnsurePolicyConsentAction $consent,
        private LockActiveWorkspaceAction $lockActive,
        private ResolveTelegramInputProjectAction $inputProject,
        private AiWorkflowRunRepositoryInterface $runs,
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private AssetRepositoryInterface $assets,
        private CreateManualProductDraftAction $create,
        private TelegramProductText $productText,
        private TelegramProductCard $card,
    ) {}

    public function execute(Workspace $workspace, User $user, string $messageKey, string $input): BotReply
    {
        $parsed = $this->productText->parse($input, "نام محصول را در خط اول و قیمت را (اختیاری) با واحد در خط دوم بنویس:\n/product کیف چرم مشکی\n۱۲۰٬۰۰۰ تومان");
        if (is_string($parsed)) {
            return new BotReply($parsed);
        }
        try {
            $this->consent->execute($workspace);
        } catch (DomainException) {
            return new BotReply('پیش از ثبت محصول، شرایط و سیاست داده را با /start بپذیر.');
        }
        // Held to the end of the webhook transaction: two /product messages
        // never claim the same photo.
        $this->lockActive->execute($workspace);
        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.');
        }
        // The batch is open until approval; an approved one opens a new batch (ADR-041).
        [$project, $notice] = $this->inputProject->execute($workspace);

        // A photo still being imported would land on the next product instead (ADR-024).
        if ($this->intakes->pendingForProject($project)) {
            return new BotReply('عکس‌ها هنوز در حال دریافت‌اند؛ چند لحظه بعد /product را دوباره بفرست.');
        }
        RateLimiter::hit($limit, 60);

        // Photos an extraction run is reading stay with the run (ADR-032).
        $reading = $this->runs->openForProject($project)->input_asset_ids ?? [];
        $free = array_values(array_filter(
            $this->assets->unclaimedInProject($project, self::MAX_IMAGES + 1 + count($reading)),
            fn ($asset): bool => ! in_array($asset->public_id, $reading, true),
        ));
        $photos = array_slice($free, 0, self::MAX_IMAGES);
        $left = count($free) > self::MAX_IMAGES;
        // The raw line goes to the builder, so changed text is kept under originals (ADR-017).
        $draft = ['name' => ['value' => $parsed['name'], 'status' => 'confirmed']];
        if ($parsed['price'] !== null) {
            $draft['pricing'] = ['regular_price' => $parsed['price'], 'input_unit' => $parsed['unit'], 'status' => 'confirmed'];
        }
        if ($photos !== []) {
            $draft['image_asset_ids'] = array_map(fn ($asset): string => $asset->public_id, $photos);
        }

        [$revision] = $this->create->execute($workspace, $user, 'telegram-'.substr($messageKey, 0, 48), $project->public_id, $draft);
        $this->assets->claim($photos, $revision->product);

        return $this->card->reply($revision->product, $revision,
            ($notice === null ? '' : $notice."\n").'محصول '.$this->card->summary($revision)." در پروژه «{$project->name}» ثبت شد.\n"
            .($left ? "عکس‌های بیشتر از ۱۰ تا برای /product بعدی می‌مانند.\n" : '')
            .'اگر درست است تأیید کن؛ اگر نه، رد یا اصلاحش کن.',
        );
    }
}
