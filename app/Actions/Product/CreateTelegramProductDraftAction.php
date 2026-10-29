<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Privacy\EnsurePolicyConsentAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Services\ProductIntake\PersianTextNormalizer;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `/product` in the bot (ADR-025): the seller types the name on the first line
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
        private ProjectRepositoryInterface $projects,
        private TelegramMediaIntakeRepositoryInterface $intakes,
        private AssetRepositoryInterface $assets,
        private CreateManualProductDraftAction $create,
        private PersianTextNormalizer $text,
    ) {}

    public function execute(Workspace $workspace, User $user, string $messageKey, string $input): BotReply
    {
        $parsed = $this->parse($input);
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
        $project = $this->projects->latestInState($workspace, ProjectState::CollectingInput);
        if ($project === null) {
            return new BotReply('پروژه بازی نداری؛ اول با /new یک پروژه بساز.');
        }

        // A photo still being imported would land on the next product instead (ADR-024).
        if ($this->intakes->pendingForProject($project)) {
            return new BotReply('عکس‌ها هنوز در حال دریافت‌اند؛ چند لحظه بعد /product را دوباره بفرست.');
        }
        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.');
        }
        RateLimiter::hit($limit, 60);

        $photos = $this->assets->unclaimedInProject($project, self::MAX_IMAGES);
        $left = count($this->assets->unclaimedInProject($project, self::MAX_IMAGES + 1)) > self::MAX_IMAGES;
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

        $price = $parsed['price'] === null
            ? 'بدون قیمت'
            : 'قیمت '.number_format($parsed['price']).' '.($parsed['unit'] === 'IRR' ? 'ریال' : 'تومان');

        return new BotReply(
            "محصول «{$revision->payload['name']['value']}» با ".count($photos)." عکس و {$price} در پروژه «{$project->name}» ثبت شد.\n"
            .($left ? "عکس‌های بیشتر از ۱۰ تا برای /product بعدی می‌مانند.\n" : '')
            .'اگر درست است تأیید کن. اگر اشتباه است تأیید نکن؛ اصلاح محصول از ربات هنوز فعال نیست.',
            // Gate A of exactly this revision (ADR-026).
            [[['text' => 'تأیید محصول', 'callback_data' => "product:approve:{$revision->product->public_id}:{$revision->revision}"]]],
        );
    }

    /**
     * @return array{name: string, price: int|null, unit: 'IRR'|'IRT'|null}|string the draft parts, or the reply explaining the format
     */
    private function parse(string $input): array|string
    {
        $help = "نام محصول را در خط اول و قیمت را (اختیاری) با واحد در خط دوم بنویس:\n/product کیف چرم مشکی\n۱۲۰٬۰۰۰ تومان";
        $lines = preg_split('/\R/u', trim($input)) ?: [];
        if (count($lines) > 2) {
            return $help;
        }
        $name = trim($lines[0] ?? '');
        if ($this->text->line($name) === '') {
            return $help;
        }
        if (mb_strlen($name) > 200) {
            return 'نام محصول باید حداکثر ۲۰۰ نویسه باشد.';
        }
        // Direction marks and ZWNJ from Persian keyboards carry no meaning in a price.
        $priceLine = preg_replace('/[\x{200B}-\x{200F}\x{2066}-\x{2069}]/u', '', $this->text->sku($lines[1] ?? '')) ?? '';
        if ($priceLine === '') {
            return ['name' => $name, 'price' => null, 'unit' => null];
        }
        // Separators only between groups of three: 12.5 or 1,5 is never read as 125 or 15.
        if (preg_match('/^([0-9]{1,3}(?:[,٬.][0-9]{3})+|[0-9]+)\s*(تومان|ریال)?$/u', $priceLine, $match) !== 1) {
            return "قیمت را فقط با عدد صحیح و واحد بنویس، مثلاً ۱۲۰٬۰۰۰ تومان.\n".$help;
        }
        // PRD E04: an amount without its unit is asked about, never assumed.
        if (($match[2] ?? '') === '') {
            return 'واحد قیمت را بنویس: تومان یا ریال، مثلاً ۱۲۰٬۰۰۰ تومان.';
        }
        $digits = str_replace([',', '٬', '.'], '', $match[1]);
        if (strlen($digits) > 12 || (int) $digits < 1) {
            return 'قیمت معتبر نیست.';
        }

        return ['name' => $name, 'price' => (int) $digits, 'unit' => $match[2] === 'ریال' ? 'IRR' : 'IRT'];
    }
}
