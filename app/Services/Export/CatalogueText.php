<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * The text every catalogue output shows of an approved payload (ADR-052,
 * ADR-053). The PDF page and the story image use the same rules, so a price
 * or unit never differs between two files of one pack.
 */
final readonly class CatalogueText
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** @param  array<string, mixed>  $payload */
    public function name(array $payload): string
    {
        return $this->plain((string) ($payload['name']['value'] ?? ''));
    }

    /** @param  array<string, mixed>  $payload */
    public function description(array $payload, bool $multiline = true): ?string
    {
        $description = $payload['description_fa']['value'] ?? null;

        return is_string($description) && trim($description) !== '' ? $this->plain($description, $multiline) : null;
    }

    /** @param  array<string, mixed>  $payload */
    public function price(array $payload): string
    {
        $irr = $payload['pricing']['regular_price_irr'] ?? null;
        if (! is_int($irr)) {
            return 'قیمت: از فروشنده بپرسید';
        }
        $toman = ($payload['pricing']['display_unit'] ?? null) === 'toman';

        return 'قیمت: '.$this->digits(number_format($toman ? intdiv($irr, 10) : $irr, 0, '', '٬')).($toman ? ' تومان' : ' ریال');
    }

    /**
     * Known attributes, then code and category; an attribute without a value is left out.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function details(array $payload): array
    {
        $lines = [];
        foreach ($payload['attributes'] ?? [] as $attribute) {
            if (is_array($attribute['values'] ?? null) && $attribute['values'] !== []) {
                $lines[] = $this->plain((string) $attribute['name']).': '.$this->plain(implode('، ', $attribute['values']));
            }
        }
        $sku = $payload['sku']['value'] ?? null;
        if (is_string($sku)) {
            // Embedded left to right, so "BAG-1" is not shown as "1-BAG".
            $lines[] = 'کد: '."\u{202A}".$this->plain($sku)."\u{202C}";
        }
        $path = $payload['category_path']['value'] ?? null;
        if (is_array($path) && $path !== []) {
            $lines[] = 'دسته: '.$this->plain(implode(' › ', $path));
        }

        return $lines;
    }

    /** Text without bidi or other format controls (ZWNJ and ZWJ stay), so no value reorders a line. */
    public function plain(string $value, bool $multiline = false): string
    {
        $value = (string) preg_replace('/[^\P{Cf}\x{200C}\x{200D}]/u', '', $value);

        return $multiline ? $value : (string) preg_replace('/\s*\R\s*/u', ' / ', $value);
    }

    public function digits(string $value): string
    {
        return str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::PERSIAN_DIGITS, $value);
    }
}
