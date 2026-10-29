<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\ProductIntake\PersianTextNormalizer;

/**
 * The seller's product text in the bot (ADR-025, ADR-029): the name on the
 * first line and, optionally, the price with its unit on the second. No AI
 * reads it; anything else is answered with the format.
 */
final readonly class TelegramProductText
{
    public function __construct(private PersianTextNormalizer $text) {}

    /**
     * @param  string  $help  the format reply of the calling command
     * @return array{name: string, price: int|null, unit: 'IRR'|'IRT'|null}|string the parts, or the reply explaining the format
     */
    public function parse(string $input, string $help): array|string
    {
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
