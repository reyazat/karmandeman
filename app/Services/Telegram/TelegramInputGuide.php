<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;

/**
 * What the seller does next after the bot accepts an input (ADR-058): every
 * receipt for a photo or its text names the next step and carries the
 * buttons for it, so the seller never waits on a bot that has nothing more
 * to say.
 */
final class TelegramInputGuide
{
    public const REVIEW = [['text' => 'بررسی پروژهٔ جاری', 'callback_data' => 'menu:done']];

    public const HOME = [['text' => 'منوی اصلی', 'callback_data' => 'menu:home']];

    public const NEXT_STEP = 'مرحله بعد: عکس محصول بعدی را بفرست. وقتی همه عکس‌ها و مشخصات را فرستادی، «بررسی پروژهٔ جاری» را بزن تا پیش‌فاکتور استخراج را ببینی و کار شروع شود.';

    public static function afterInput(string $receipt): BotReply
    {
        return new BotReply($receipt."\n\n".self::NEXT_STEP, [self::REVIEW, self::HOME]);
    }
}
