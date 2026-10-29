<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;
use App\Models\Product;
use App\Models\ProductRevision;

/**
 * One product revision as the bot shows it, with the Gate A button for exactly
 * that revision (ADR-026, ADR-027). The button never appears without the name,
 * photo count, price and unit it approves.
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
        return new BotReply($text, [[[
            'text' => 'تأیید محصول',
            'callback_data' => "product:approve:{$product->public_id}:{$revision->revision}",
        ]]]);
    }
}
