<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\ProductIntake\PersianTextNormalizer;

/**
 * A field-by-field correction typed in reply to a product card (ADR-051):
 * one "label: value" per line. Only the fields named change. "حذف" removes a
 * value and "نامعلوم" marks it unknown; a price is never assumed from a bare
 * number. Labels other than the fixed ones name an attribute. No AI reads it.
 */
final readonly class TelegramProductCorrection
{
    public const HELP = "برای اصلاح هر مورد، به کارت پاسخ بده و هر مورد را در یک خط بنویس:\n".
        "نام: کیف چرم مشکی\nقیمت: ۱۲۰٬۰۰۰ تومان (یا: نامعلوم)\nتوضیح: …\nکد: BAG-1\nدسته: کیف › روزمره\nجنس: برزنت (هر ویژگی با نام خودش)\n".
        'برای پاک‌کردن یک مورد بنویس «حذف»، مثلاً «توضیح: حذف». موردهایی که نمی‌نویسی تغییر نمی‌کنند.';

    private const MAX_LINES = 40;

    private const MAX_DESCRIPTION = 1000;

    private const LABELS = [
        'نام' => 'name', 'قیمت' => 'price', 'توضیح' => 'description_fa', 'توضیحات' => 'description_fa',
        'کد' => 'sku', 'sku' => 'sku', 'دسته' => 'category', 'دسته‌بندی' => 'category',
    ];

    private const REMOVE = ['حذف', 'پاک'];

    private const UNKNOWN = ['نامعلوم', 'نامشخص'];

    public function __construct(
        private PersianTextNormalizer $text,
        private TelegramProductText $productText,
    ) {}

    /** Colons a phone keyboard may type in place of ':'. */
    private const COLONS = ['：', '﹕', '꞉'];

    /**
     * Whether every line has a label; otherwise it is the name-and-price form
     * (ADR-029), so "ست: سیاه" over a price line still renames the product.
     */
    public function applies(string $input): bool
    {
        $lines = $this->lines($input);

        return $lines !== [] && array_filter($lines, fn (string $line): bool => ! str_contains($line, ':')) === [];
    }

    /**
     * The changes, keyed by field; an attribute is keyed by its normalized name,
     * and its values false removes it and null marks it unknown.
     *
     * Each field label is kept as typed, so the action can refuse a label that
     * also names a stored attribute.
     *
     * @return array{fields: array<string, mixed>, labels: array<string, string>, attributes: array<string, array{name: string, values: list<string>|false|null}>}|string
     */
    public function parse(string $input): array|string
    {
        $lines = $this->lines($input);
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            return self::HELP;
        }
        $fields = [];
        $labels = [];
        $attributes = [];
        foreach ($lines as $line) {
            $colon = mb_strpos($line, ':');
            $label = $colon === false ? '' : $this->text->line(mb_substr($line, 0, $colon));
            $value = $colon === false ? '' : trim(mb_substr($line, $colon + 1));
            if ($label === '' || $this->text->line($value) === '') {
                return "هر خط باید «نام مورد: مقدار» باشد.\n".self::HELP;
            }
            $key = $this->text->nameKey($label);
            if ($key === '') {
                return "هر خط باید «نام مورد: مقدار» باشد.\n".self::HELP;
            }
            // Compared as names are, so spacing, ZWNJ or Arabic letters do not hide a label.
            $field = null;
            foreach (self::LABELS as $known => $mapped) {
                if ($this->text->nameKey($known) === $key) {
                    $field = $mapped;
                }
            }
            $remove = in_array($this->text->line($value), self::REMOVE, true);
            $unknown = in_array($this->text->line($value), self::UNKNOWN, true);
            if ($field === null) {
                if (array_key_exists($key, $attributes)) {
                    return "«{$label}» دو بار آمده است؛ هر مورد را یک بار بنویس.";
                }
                $values = match (true) {
                    $remove => false,
                    $unknown => null,
                    default => $this->items($value, '/[،,]/u'),
                };
                if ($values === []) {
                    return "مقدار «{$label}» خالی است.";
                }
                $attributes[$key] = ['name' => $label, 'values' => $values];

                continue;
            }
            if (array_key_exists($field, $fields)) {
                return "«{$label}» دو بار آمده است؛ هر مورد را یک بار بنویس.";
            }
            $parsed = $this->field($field, $value, $remove || $unknown);
            if (is_string($parsed)) {
                return $parsed;
            }
            $fields[$field] = $parsed['value'];
            $labels[$field] = $label;
        }

        return ['fields' => $fields, 'labels' => $labels, 'attributes' => $attributes];
    }

    /** @return list<string> the non-empty lines, with phone-keyboard colons read as ':' */
    private function lines(string $input): array
    {
        $input = str_replace(self::COLONS, ':', $input);

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', trim($input)) ?: []), fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array{value: mixed}|string
     */
    private function field(string $field, string $value, bool $cleared): array|string
    {
        if ($field === 'name') {
            return $cleared ? 'نام محصول حذف نمی‌شود؛ نام تازه را بنویس.' : ['value' => $value];
        }
        if ($cleared) {
            return ['value' => null];
        }

        if ($field === 'price') {
            $price = $this->productText->price($value, self::HELP);
            if ($price === []) {
                return 'قیمت را با عدد و واحد بنویس، مثلاً «قیمت: ۱۲۰٬۰۰۰ تومان»، یا «قیمت: نامعلوم».';
            }

            return is_string($price) ? $price : ['value' => $price];
        }
        if ($field === 'category') {
            $levels = $this->items($value, '/[›>\/]/u');

            return $levels === [] ? 'دسته خالی است.' : ['value' => $levels];
        }
        if ($field === 'description_fa' && mb_strlen($value) > self::MAX_DESCRIPTION) {
            // Shorter than the API's 5000, so the card that shows it still fits one message.
            return 'توضیح از ربات حداکثر '.self::MAX_DESCRIPTION.' نویسه است.';
        }

        return ['value' => $value];
    }

    /** @return list<string> */
    private function items(string $value, string $separator): array
    {
        return array_values(array_filter(array_map('trim', preg_split($separator, $value) ?: []), fn (string $item): bool => $this->text->line($item) !== ''));
    }
}
