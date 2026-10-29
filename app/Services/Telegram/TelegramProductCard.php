<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;
use App\Models\Product;
use App\Models\ProductRevision;

/**
 * One product revision as the bot shows it, with the Gate A button for exactly
 * that revision, and the reject button (ADR-026 to ADR-028). The buttons never
 * appear without the name, photo count, price and unit they act on. A reply to
 * the card corrects that same revision (ADR-029).
 */
final readonly class TelegramProductCard
{
    /** «name» با N عکس و قیمت X تومان */
    public function summary(ProductRevision $revision): string
    {
        $payload = $revision->payload;
        $irr = $payload['pricing']['regular_price_irr'] ?? null;
        $price = match (true) {
            $irr === null => 'بدون قیمت',
            ($payload['pricing']['display_unit'] ?? null) === 'toman' => 'قیمت '.number_format(intdiv((int) $irr, 10)).' تومان',
            default => 'قیمت '.number_format((int) $irr).' ریال',
        };

        return '«'.($payload['name']['value'] ?? '—').'» با '.count($payload['image_asset_ids'] ?? []).' عکس و '.$price;
    }

    public function reply(Product $product, ProductRevision $revision, string $text): BotReply
    {
        return new BotReply($text."\nبرای اصلاح، به همین پیام پاسخ (Reply) بده: نام در خط اول، قیمت تازه در خط دوم.", [[
            ['text' => 'تأیید محصول', 'callback_data' => "product:approve:{$product->public_id}:{$revision->revision}"],
            ['text' => 'رد محصول', 'callback_data' => "product:reject:{$product->public_id}:{$revision->revision}"],
        ]]);
    }
}
