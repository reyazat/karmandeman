<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Project\ReopenForCorrectionAction;
use App\Data\Telegram\BotReply;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\ProductIntake\PersianTextNormalizer;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\ProductIntake\ProductDraftRules;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use App\Services\Telegram\TelegramProductCard;
use App\Services\Telegram\TelegramProductCorrection;
use App\Services\Telegram\TelegramProductText;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

/**
 * Correction from the bot (ADR-029, ADR-051): the seller replies to a product
 * card with the name and, optionally, a new price, or with "label: value" lines
 * for any field. The card names the revision corrected, so a newer revision is
 * never overwritten. Fields not named and the photos stay, with their status and
 * provenance. The result passes the same draft rules as the API. An approved
 * batch is reopened by the correction itself (ADR-041). Refusals are answered.
 */
final readonly class ReviseTelegramProductAction
{
    public function __construct(
        private ReviseProductDraftAction $revise,
        private ProductRepositoryInterface $products,
        private ProjectRepositoryInterface $projects,
        private ReopenForCorrectionAction $reopen,
        private ProductDraftNormalizer $normalizer,
        private PersianTextNormalizer $text,
        private ProjectSnapshotSigner $signer,
        private TelegramProductText $productText,
        private TelegramProductCorrection $correction,
        private ProductDraftRules $rules,
        private ValidationFactory $validation,
        private TelegramProductCard $card,
    ) {}

    public function execute(Workspace $workspace, User $user, string $messageKey, string $productPublicId, int $revision, string $input): BotReply
    {
        $labelled = $this->correction->applies($input);
        $parsed = $labelled ? $this->correction->parse($input) : $this->productText->parse($input,
            "برای اصلاح، نام محصول را در خط اول و قیمت تازه را (اختیاری) با واحد در خط دوم بنویس:\nکیف چرم مشکی\n۱۲۰٬۰۰۰ تومان\nبدون خط دوم، قیمت قبلی می‌ماند.\n\n".TelegramProductCorrection::HELP);
        if (is_string($parsed)) {
            return new BotReply($parsed);
        }
        $product = $this->products->findPublicForWorkspace($workspace, $productPublicId);
        // The revision the card showed: immutable, so reading it outside the lock is safe.
        $shown = $product === null ? null : $this->products->revision($product, $revision);
        if ($product === null || $shown === null || Gate::forUser($user)->denies('update', $product)) {
            return new BotReply('محصول یافت نشد.');
        }
        $limit = 'telegram-product:'.$user->id;
        if (RateLimiter::tooManyAttempts($limit, (int) config('karmandeman.assets.uploads_per_minute'))) {
            return new BotReply('محصول‌ها خیلی سریع فرستاده شدند؛ یک دقیقه صبر کن.');
        }
        RateLimiter::hit($limit, 60);

        // The other fields are copied into a revision that gets a fresh MAC: a stored payload
        // that does not match its own MAC is never laundered through a correction.
        if (! hash_equals((string) $shown->payload_mac, $this->signer->payloadMac($workspace, $shown))) {
            return new BotReply('نسخه این محصول قابل اعتماد نیست و از ربات اصلاح نمی‌شود.');
        }
        $draft = $this->normalizer->draftFrom($shown->payload);
        $added = [];
        if ($labelled) {
            $applied = $this->apply($draft, $parsed);
            if (is_string($applied)) {
                return new BotReply($applied);
            }
            [$draft, $added] = $applied;
        } else {
            $draft['name'] = ['value' => $parsed['name'], 'status' => 'confirmed'];
            if ($parsed['price'] !== null) {
                $draft['pricing'] = ['regular_price' => $parsed['price'], 'input_unit' => $parsed['unit'], 'status' => 'confirmed'];
            }
        }
        $invalid = $this->invalid($draft);
        if ($invalid !== null) {
            return new BotReply($invalid);
        }
        $project = $product->project_id === null ? null : $this->projects->findForWorkspace($workspace, $product->project_id);
        $key = 'telegram-'.substr($messageKey, 0, 48);
        try {
            // An approved batch reopens with the correction itself, no extra press (ADR-041).
            // An outdated card never gets here: it fails the revision check and reopens nothing.
            [$revised, $notice] = DB::transaction(function () use ($workspace, $user, $key, $project, $product, $productPublicId, $revision, $draft): array {
                $notice = null;
                if ($project !== null && $revision === $product->current_revision) {
                    [, $notice] = $this->reopen->execute($workspace, $user, "{$key}-reopen", $project);
                }
                [$revised] = $this->revise->execute($workspace, $user, $key, $productPublicId, $revision, $draft);

                return [$revised, $notice];
            });
        } catch (AuthorizationException) {
            return new BotReply('محصول یافت نشد.');
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                // An outdated card can never apply, so it must not invalidate the approved batch.
                'PROJECT_REOPEN_REQUIRED' => new BotReply('این محصول پس از این پیام تغییر کرده است؛ به کارت نسخه تازه پاسخ بده.'),
                'PROJECT_PAST_APPROVAL' => new BotReply("پروژه «{$project?->name}» از مرحله تأیید گذشته است و محصولش اصلاح نمی‌شود."),
                'PROJECT_VERSION_CONFLICT' => new BotReply('پروژه همین حالا تغییر کرد؛ دوباره به کارت پاسخ بده.'),
                'PRODUCT_NOT_FOUND' => new BotReply('محصول یافت نشد.'),
                'PRODUCT_REVISION_CONFLICT' => new BotReply('این محصول پس از این پیام تغییر کرده است؛ به کارت نسخه تازه پاسخ بده.'),
                'PRODUCT_ARCHIVED' => new BotReply('این محصول رد شده است و اصلاح نمی‌شود.'),
                'PROJECT_CANCELLED' => new BotReply('پروژه این محصول لغو شده است.'),
                'POLICY_CONSENT_REQUIRED' => new BotReply('پیش از اصلاح، شرایط و سیاست داده را با /start بپذیر.'),
                'PRODUCT_SKU_TAKEN' => new BotReply('این کد (SKU) برای محصول دیگری ثبت شده است؛ کد دیگری بنویس.'),
                // Never thrown: a rollback would also drop the reply.
                default => new BotReply('اصلاح انجام نشد؛ دوباره پاسخ بده.'),
            };
        }

        return $this->card->reply($revised->product, $revised,
            ($notice ?? '').'محصول اصلاح شد: '.$this->card->summary($revised)." (نسخه {$revised->revision}).\nنسخه تازه را تأیید کن؛ تأیید قبلی برای آن معتبر نیست."
            // A label that was meant as part of the name shows up here, not silently.
            .($added === [] ? '' : "\nویژگی تازه افزوده شد: ".implode('، ', array_map(fn (string $name): string => "«{$name}»", $added)).'. اگر منظورت نام محصول بود، بنویس «نام: …» و این ویژگی را با «'.$added[0].': حذف» پاک کن.'),
        );
    }

    /**
     * The named fields changed and confirmed; nothing else is touched (ADR-051).
     *
     * @param  array<string, mixed>  $draft
     * @param  array{fields: array<string, mixed>, labels: array<string, string>, attributes: array<string, array{name: string, values: list<string>|false|null}>}  $changes
     * @return array{array<string, mixed>, list<string>}|string the draft and the attributes it adds, or the reply when the change is ambiguous
     */
    private function apply(array $draft, array $changes): array|string
    {
        $attributes = $draft['attributes'] ?? [];
        $matches = function (string $key) use (&$attributes): array {
            return array_keys(array_filter($attributes, fn (array $attribute): bool => $this->text->nameKey((string) $attribute['name']) === $key));
        };
        // A field label that also names a stored attribute could mean either: ask, never guess.
        foreach ($changes['labels'] as $label) {
            if ($matches($this->text->nameKey($label)) !== []) {
                return "این محصول ویژگی‌ای هم‌نام «{$label}» دارد؛ از ربات معلوم نیست کدام اصلاح شود. از /support کمک بگیر.";
            }
        }
        foreach ($changes['fields'] as $field => $value) {
            $target = match ($field) {
                'price' => 'pricing',
                'category' => 'category_path',
                default => $field,
            };
            if ($value === null) {
                unset($draft[$target]);
            } elseif ($field === 'price') {
                $draft['pricing'] = ['regular_price' => $value['amount'], 'input_unit' => $value['unit'], 'status' => 'confirmed'];
            } else {
                $draft[$target] = ['value' => $value, 'status' => 'confirmed'];
            }
        }
        $added = [];
        foreach ($changes['attributes'] as $key => $change) {
            $found = $matches($key);
            if (count($found) > 1) {
                return "این محصول چند ویژگی با نام «{$change['name']}» دارد؛ از ربات اصلاح نمی‌شود.";
            }
            $index = $found[0] ?? null;
            if ($change['values'] === false) {
                if ($index === null) {
                    return "ویژگی «{$change['name']}» روی این محصول نیست.";
                }
                unset($attributes[$index]);

                continue;
            }
            $attribute = [
                'name' => $index === null ? $change['name'] : $attributes[$index]['name'],
                'values' => $change['values'],
                'status' => $change['values'] === null ? 'unknown' : 'confirmed',
            ];
            if ($index === null) {
                $attributes[] = $attribute;
                $added[] = $change['name'];
            } else {
                $attributes[$index] = $attribute;
            }
        }
        $draft['attributes'] = array_values($attributes);

        return [$draft, $added];
    }

    /**
     * The same limits as the API and the n8n callback (ProductDraftRules).
     *
     * @param  array<string, mixed>  $draft
     */
    private function invalid(array $draft): ?string
    {
        $validator = $this->validation->make($draft, ProductDraftRules::rules());
        $validator->after(function (Validator $validator) use ($draft): void {
            $this->rules->checkText($validator, $draft);
            $this->rules->checkStatuses($validator, $draft);
        });
        if (! $validator->fails()) {
            return null;
        }
        $path = (string) array_key_first($validator->errors()->messages());

        return match (true) {
            str_starts_with($path, 'name') => 'نام محصول باید حداکثر ۲۰۰ نویسه باشد.',
            str_starts_with($path, 'sku') => 'کد (SKU) باید یک خط و حداکثر ۶۴ نویسه باشد.',
            str_starts_with($path, 'description_fa') => 'توضیح باید حداکثر ۵۰۰۰ نویسه باشد.',
            str_starts_with($path, 'category_path') => 'دسته حداکثر ۵ سطح دارد و هر سطح حداکثر ۱۰۰ نویسه است.',
            str_starts_with($path, 'attributes') => 'هر محصول حداکثر ۳۰ ویژگی دارد؛ نام ویژگی حداکثر ۱۰۰ و هر مقدار حداکثر ۲۰۰ نویسه است و هر ویژگی حداکثر ۲۰ مقدار دارد.',
            default => 'اصلاح پذیرفته نشد؛ مقدارها را بررسی کن.',
        };
    }
}
