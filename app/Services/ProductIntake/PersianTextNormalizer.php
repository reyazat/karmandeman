<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use InvalidArgumentException;
use Normalizer;

/**
 * Canonical Persian text for product fields (PRD E04, ADR-017): Arabic letter
 * forms, Arabic-Indic digits, ZWNJ and whitespace. The raw input is kept by
 * the caller; this class only computes the stored form.
 */
final class PersianTextNormalizer
{
    private const ZWNJ = "\u{200C}";

    private const LETTERS = [
        "\u{064A}" => "\u{06CC}", // Arabic yeh
        "\u{0649}" => "\u{06CC}", // alef maksura
        "\u{0643}" => "\u{06A9}", // Arabic kaf
        "\u{0640}" => '',         // tatweel
    ];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** Letter forms a seller may type for one name; only for the duplicate key. */
    private const NAME_LETTERS = [
        "\u{0623}" => "\u{0627}", // alef with hamza above
        "\u{0625}" => "\u{0627}", // alef with hamza below
        "\u{0671}" => "\u{0627}", // alef wasla
        "\u{0622}" => "\u{0627}", // alef with madda
        "\u{0624}" => "\u{0648}", // waw with hamza
        "\u{0626}" => "\u{06CC}", // yeh with hamza
        "\u{0629}" => "\u{0647}", // teh marbuta
        "\u{06C0}" => "\u{0647}", // heh with yeh above
        "\u{06D5}" => "\u{0647}", // ae
        "\u{0621}" => '',          // standalone hamza
    ];

    /** Multi-line text such as a description: paragraph breaks are kept. */
    public function text(string $value): string
    {
        return $this->normalize($value, multiline: true);
    }

    /** Single-line text such as a name, attribute or category. */
    public function line(string $value): string
    {
        return $this->normalize($value, multiline: false);
    }

    /** A SKU as stored: single line with Latin digits. */
    public function sku(string $value): string
    {
        return str_replace([...self::PERSIAN_DIGITS, ...self::ARABIC_DIGITS], [...self::LATIN_DIGITS, ...self::LATIN_DIGITS], $this->line($value));
    }

    /**
     * Comparison key for SKU uniqueness: compatibility form, case-insensitive.
     * NFKC runs first, so presentation forms (U+FEF1) reach the letter map too.
     */
    public function skuKey(string $value): string
    {
        return mb_strtoupper($this->sku($this->unicode($value, Normalizer::FORM_KC)), 'UTF-8');
    }

    /**
     * Comparison key for the near-duplicate warning (FR-034, ADR-020): letters
     * and digits only, so spacing, ZWNJ, diacritics and punctuation do not
     * matter. Empty when nothing comparable is left.
     */
    public function nameKey(string $value): string
    {
        $value = strtr($this->sku($this->unicode($value, Normalizer::FORM_KC)), self::NAME_LETTERS);
        $value = $this->replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value);
        // Between digits a separator is part of a size: 1.5 is not 15, 40-41 is not 4041.
        $value = $this->replace('/(?<=\p{N})[^\p{L}\p{N}]+(?=\p{N})/u', '.', $value);

        return mb_strtolower($this->replace('/[^\p{L}\p{N}.]+|(?<!\p{N})\.|\.(?!\p{N})/u', '', $value), 'UTF-8');
    }

    private function normalize(string $value, bool $multiline): string
    {
        $value = strtr($this->unicode($value, Normalizer::FORM_C), self::LETTERS);
        $value = str_replace(self::ARABIC_DIGITS, self::PERSIAN_DIGITS, $value);
        // Every vertical break (CR, VT, FF, NEL, U+2028/9) is a newline first.
        $value = $this->replace('/\r\n|\v/u', "\n", $value);
        if (! $multiline) {
            $value = str_replace("\n", ' ', $value);
        }

        $value = $this->replace('/\x{200C}+/u', self::ZWNJ, $value);
        // A ZWNJ joins two letters; next to a space it only hides the space.
        $value = $this->replace('/\h+\x{200C}\h*|\x{200C}\h+/u', ' ', $value);
        $value = $this->replace('/\h+/u', ' ', $value);
        $value = $this->replace('/ ?\n ?/', "\n", $value);
        $value = $this->replace('/^\x{200C}+|\x{200C}+$/um', '', $value);
        $value = $this->replace('/\n{3,}/', "\n\n", $value);

        return trim($value, " \n");
    }

    private function unicode(string $value, int $form): string
    {
        $normalized = Normalizer::normalize($value, $form);
        if ($normalized === false) {
            throw new InvalidArgumentException('Text is not valid UTF-8.');
        }

        return $normalized;
    }

    private function replace(string $pattern, string $replacement, string $value): string
    {
        return preg_replace($pattern, $replacement, $value) ?? throw new InvalidArgumentException('Text is not valid UTF-8.');
    }
}
