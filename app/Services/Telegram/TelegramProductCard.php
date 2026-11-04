<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Services\ProductIntake\ProductDraftNormalizer;

/**
 * One product revision as the bot shows it, with the Gate A button for exactly
 * that revision, and the reject button (ADR-026 to ADR-028). The buttons never
 * appear without the name, photo count, price and unit they act on. A reply to
 * the card corrects that same revision (ADR-029). A revision with suggested
 * values lists every one of them, the attribute names kept without a value and
 * the photos the model chose, and offers to confirm exactly those, with or
 * without the price, instead of Gate A (ADR-034). Shown text carries no bidi
 * or other format controls, so a value cannot disguise another line.
 */
final readonly class TelegramProductCard
{
    /** UTF-16 units for the suggested values, well under Telegram's 4096 per message. */
    private const SUGGESTED_BUDGET = 3000;

    private const REPLY_HINT = "\nبرای اصلاح، به همین پیام پاسخ (Reply) بده: نام در خط اول و قیمت تازه در خط دوم، یا هر مورد در یک خط مثل «جنس: برزنت» یا «قیمت: نامعلوم».";

    public function __construct(
        private ProductDraftNormalizer $normalizer,
        private AssetRepositoryInterface $assets,
    ) {}

    /** «name» با N عکس و قیمت X تومان */
    public function summary(ProductRevision $revision): string
    {
        $payload = $revision->payload;

        return '«'.$this->shown($payload['name']['value'] ?? '—').'» با '.count($payload['image_asset_ids'] ?? []).' عکس و '.$this->price($payload);
    }

    /**
     * A grouped question row (ADR-035): the price first, then the name as a
     * short label without quote marks, so no name can pose as a price.
     */
    public function priceLine(ProductRevision $revision): string
    {
        return $this->price($revision->payload).' — '.$this->priceLabel($revision);
    }

    /** The name a grouped question row is told apart by. */
    public function priceLabel(ProductRevision $revision): string
    {
        return mb_strimwidth(str_replace(['«', '»'], '"', $this->shown((string) ($revision->payload['name']['value'] ?? '—'))), 0, 60, '…');
    }

    public function reply(Product $product, ProductRevision $revision, string $text): BotReply
    {
        $id = "{$product->public_id}:{$revision->revision}";
        $reject = ['text' => 'رد محصول', 'callback_data' => "product:reject:{$id}"];
        [$suggested, $kept, $confirmed] = $this->review($revision);
        if ($this->normalizer->fieldsRequiringConfirmation($revision->payload) === []) {
            $kept = [...$kept, ...$confirmed];
            // Gate A also approves what the model chose without a status, so it is shown here too.
            if ($kept === []) {
                return new BotReply($text.self::REPLY_HINT, [[['text' => 'تأیید محصول', 'callback_data' => "product:approve:{$id}"], $reject]]);
            }
            $block = implode("\n", $kept);
            if (! $this->fits($block)) {
                return new BotReply($text."\n\nجزئیات این محصول برای نمایش در ربات بیش از حد طولانی‌اند و از ربات تأیید نمی‌شود.".self::REPLY_HINT, [[$reject]]);
            }

            return new BotReply($text."\n\nهمراه این محصول:\n".$block.self::REPLY_HINT, [[['text' => 'تأیید محصول', 'callback_data' => "product:approve:{$id}"], $reject]]);
        }

        $buttons = [];
        if ($suggested !== []) {
            $block = implode("\n", [...$suggested, ...$kept]);
            if ($this->fits($block."\n".implode("\n", $confirmed))) {
                $text .= "\n\nمقدارهای پیشنهادی از عکس‌ها (هنوز تأیید نشده‌اند):\n".$block
                    .($confirmed === [] ? '' : "\n\nتأییدشده:\n".implode("\n", $confirmed));
                $confirm = [['text' => 'تأیید مقدارهای پیشنهادی', 'callback_data' => "product:confirm:{$id}"]];
                if ($this->priceSuggested($revision)) {
                    $confirm[] = ['text' => 'تأیید بدون قیمت', 'callback_data' => "product:noprice:{$id}"];
                }
                $buttons[] = $confirm;
            } else {
                $text .= "\n\nمقدارهای پیشنهادی این محصول برای نمایش در ربات بیش از حد طولانی‌اند و از ربات تأیید نمی‌شوند.";
            }
        }
        if (($revision->payload['name']['value'] ?? null) === null) {
            $text .= "\nنام محصول پیدا نشد؛ با پاسخ به همین پیام نام را بنویس.";
        }
        $buttons[] = [$reject];

        return new BotReply($text.self::REPLY_HINT, $buttons);
    }

    /**
     * Whether the confirm button may act on this revision: it has suggested
     * values and the card shows all of them. The confirm action checks this
     * again, so a forged button never confirms a value the seller was not shown.
     */
    public function confirmable(ProductRevision $revision): bool
    {
        [$suggested, $kept, $confirmed] = $this->review($revision);

        return $suggested !== [] && $this->fits(implode("\n", [...$suggested, ...$kept])."\n".implode("\n", $confirmed));
    }

    public function priceSuggested(ProductRevision $revision): bool
    {
        return $this->priceSuggestedIn($revision->payload);
    }

    /**
     * Every suggested value of the revision, in full; what a confirm keeps
     * without a status: attribute names with no value and the photos the model
     * chose by their upload position; and the values already confirmed, which
     * Gate A approves too (ADR-051). Nothing is approved unseen.
     *
     * @return array{list<string>, list<string>, list<string>}
     */
    private function review(ProductRevision $revision): array
    {
        $payload = $revision->payload;
        $suggested = [];
        foreach (['name' => 'نام', 'sku' => 'SKU', 'description_fa' => 'توضیح'] as $field => $label) {
            if (($payload[$field]['status'] ?? null) === 'suggested') {
                $suggested[] = "• {$label}: ".$this->shown((string) $payload[$field]['value']);
            }
        }
        if ($this->priceSuggestedIn($payload)) {
            $suggested[] = '• '.$this->price($payload);
        }
        $kept = [];
        // Confirmed values are shown too, so Gate A never approves a value the card hides (ADR-051).
        $confirmed = [];
        foreach (['sku' => 'کد', 'description_fa' => 'توضیح'] as $field => $label) {
            if (($payload[$field]['status'] ?? null) === 'confirmed') {
                $confirmed[] = "• {$label}: ".$this->shown((string) $payload[$field]['value']);
            }
        }
        foreach ($payload['attributes'] ?? [] as $attribute) {
            $name = $this->shown($attribute['name']);
            if ($attribute['status'] === 'suggested') {
                $suggested[] = "• {$name}: ".$this->shown(implode('، ', $attribute['values'] ?? []));
            } elseif ($attribute['values'] === null) {
                $kept[] = "• {$name}: نامعلوم";
            } else {
                $confirmed[] = "• {$name}: ".$this->shown(implode('، ', $attribute['values']));
            }
        }
        $category = $payload['category_path'] ?? [];
        if (($category['status'] ?? null) === 'suggested') {
            $suggested[] = '• دسته: '.$this->shown(implode(' › ', $category['value'] ?? []));
        } elseif (($category['value'] ?? null) !== null) {
            $confirmed[] = '• دسته: '.$this->shown(implode(' › ', $category['value']));
        }
        $images = $payload['image_asset_ids'] ?? [];
        $fromModel = in_array(ProductDraftNormalizer::SOURCE_AI, array_column(
            array_filter($payload['evidence'] ?? [], fn (array $entry): bool => $entry['field'] === 'image_asset_ids'), 'source',
        ), true);
        if ($images !== [] && $fromModel) {
            $project = $revision->product->project;
            $positions = $project === null ? [] : $this->assets->positionsInProject($project, $images);
            $kept[] = '• عکس‌ها: '.implode('، ', array_map(fn (string $id): string => isset($positions[$id]) ? "عکس {$positions[$id]}" : 'عکس نامعلوم', $images))
                .' از عکس‌هایی که در این پروژه فرستادی';
        }

        return [$suggested, $kept, $confirmed];
    }

    /** One line of model or seller text with no bidi or format controls (ZWNJ and ZWJ stay). */
    private function shown(string $value): string
    {
        return (string) preg_replace(['/[^\P{Cf}\x{200C}\x{200D}]/u', '/\s*\R\s*/u'], ['', ' / '], $value);
    }

    /** @param  array<string, mixed>  $payload */
    private function priceSuggestedIn(array $payload): bool
    {
        return ($payload['pricing']['status'] ?? null) === 'suggested';
    }

    private function fits(string $block): bool
    {
        return strlen(mb_convert_encoding($block, 'UTF-16LE', 'UTF-8')) / 2 <= self::SUGGESTED_BUDGET;
    }

    /** @param  array<string, mixed>  $payload */
    private function price(array $payload): string
    {
        $irr = $payload['pricing']['regular_price_irr'] ?? null;

        return match (true) {
            $irr === null => 'بدون قیمت',
            ($payload['pricing']['display_unit'] ?? null) === 'toman' => 'قیمت '.number_format(intdiv((int) $irr, 10)).' تومان',
            default => 'قیمت '.number_format((int) $irr).' ریال',
        };
    }
}
