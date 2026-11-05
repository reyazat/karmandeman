<?php

declare(strict_types=1);

namespace App\Services\Export;

use GdImage;
use RuntimeException;
use TCPDF_FONTS;

/**
 * One fixed 1080×1920 story image of one approved product (ADR-053): the
 * brand and collection, the product's main photo, its name in at most two
 * lines, its description in at most two lines and the price in its own band.
 * Text that does not fit ends with "…"; the price is never cut, its size
 * shrinks instead. Text comes from the same rules as the PDF (CatalogueText),
 * is shaped and ordered for Persian by TCPDF's bidi and drawn with the
 * embedded Vazirmatn font. Story apps cover about the top and bottom 250 px
 * with their controls, so every text sits between those bands.
 */
final readonly class CatalogueStoryRenderer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    private const SIDE = 72;

    private const COLUMN = self::WIDTH - 2 * self::SIDE;

    private const PHOTO_TOP = 330;

    private const PHOTO_HEIGHT = 840;

    private const PRICE_TOP = 1500;

    private const PRICE_HEIGHT = 130;

    private const NAME_SIZE = 40.0;

    private const NAME_LINES = 2;

    private const DESCRIPTION_SIZE = 26.0;

    private const DESCRIPTION_LINES = 2;

    private const PRICE_SIZE = 42.0;

    private const MIN_PRICE_SIZE = 20.0;

    /** Longer text is cut before measuring: two lines never hold more. */
    private const MAX_MEASURED_CHARACTERS = 300;

    private const QUALITY = 88;

    public function __construct(private CatalogueText $text) {}

    /**
     * The text lines the image shows, in reading order.
     *
     * @param  array<string, mixed>  $payload
     * @return array{name: list<string>, description: list<string>, price: string, price_size: float}
     */
    public function lines(array $payload): array
    {
        $price = $this->text->price($payload);
        $size = self::PRICE_SIZE;
        while ($size > self::MIN_PRICE_SIZE && $this->width($price, $size, $this->font(true)) > self::COLUMN) {
            $size -= 2.0;
        }
        $description = $this->text->description($payload, multiline: false);

        return [
            'name' => $this->wrap($this->text->name($payload), self::NAME_SIZE, $this->font(true), self::NAME_LINES),
            'description' => $description === null ? [] : $this->wrap($description, self::DESCRIPTION_SIZE, $this->font(false), self::DESCRIPTION_LINES),
            'price' => $price,
            'price_size' => $size,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  the approved payload
     * @param  string|null  $photo  the main photo as metadata-free JPEG bytes (CataloguePhotoEncoder)
     * @return string the JPEG bytes
     */
    public function render(string $brand, string $collection, array $payload, ?string $photo): string
    {
        $lines = $this->lines($payload);
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, $this->color($image, 255, 255, 255));
        $grey = $this->color($image, 110, 110, 110);
        $ink = $this->color($image, 20, 20, 20);
        $body = $this->color($image, 60, 60, 60);

        $header = $this->text->plain($brand).' · '.$this->text->plain($collection);
        $this->draw($image, $this->wrap($header, 24.0, $this->font(true), 1)[0] ?? '', 24.0, true, 290, $grey, center: true);
        $this->photo($image, $photo);

        $baseline = self::PHOTO_TOP + self::PHOTO_HEIGHT + 82;
        foreach ($lines['name'] as $line) {
            $this->draw($image, $line, self::NAME_SIZE, true, $baseline, $ink);
            $baseline += 78;
        }
        $baseline += 2;
        foreach ($lines['description'] as $line) {
            $this->draw($image, $line, self::DESCRIPTION_SIZE, false, $baseline, $body);
            $baseline += 54;
        }

        imagefilledrectangle($image, 0, self::PRICE_TOP, self::WIDTH - 1, self::PRICE_TOP + self::PRICE_HEIGHT, $this->color($image, 0, 102, 85));
        $this->draw($image, $lines['price'], $lines['price_size'], true, self::PRICE_TOP + 88, $this->color($image, 255, 255, 255), center: true);

        ob_start();
        imagejpeg($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /** The photo scaled into its box and centred, on a light ground; a product without one keeps the empty ground. */
    private function photo(GdImage $image, ?string $jpeg): void
    {
        imagefilledrectangle($image, self::SIDE, self::PHOTO_TOP, self::SIDE + self::COLUMN - 1, self::PHOTO_TOP + self::PHOTO_HEIGHT - 1, $this->color($image, 244, 244, 244));
        if ($jpeg === null) {
            return;
        }
        $source = @imagecreatefromstring($jpeg);
        if ($source === false) {
            throw new RuntimeException('Story photo could not be decoded.');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(self::COLUMN / $width, self::PHOTO_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled(
            $image, $source,
            self::SIDE + intdiv(self::COLUMN - $targetWidth, 2), self::PHOTO_TOP + intdiv(self::PHOTO_HEIGHT - $targetHeight, 2),
            0, 0, $targetWidth, $targetHeight, $width, $height,
        );
    }

    /**
     * Words in lines of the column width, at most $max lines; a cut text ends with "…".
     *
     * @return list<string>
     */
    private function wrap(string $text, float $size, string $font, int $max): array
    {
        $cut = mb_strlen($text) > self::MAX_MEASURED_CHARACTERS;
        $words = preg_split('/\s+/u', trim(mb_substr($text, 0, self::MAX_MEASURED_CHARACTERS)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->width($candidate, $size, $font) <= self::COLUMN) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
            // A single word wider than the column is broken where it fills a line.
            while ($this->width($line, $size, $font) > self::COLUMN && mb_strlen($line) > 1) {
                $fit = $this->fitting($line, $size, $font, '');
                $lines[] = mb_substr($line, 0, $fit);
                $line = mb_substr($line, $fit);
            }
            if (count($lines) > $max) {
                break;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) <= $max && ! $cut) {
            return $lines;
        }
        $lines = array_slice($lines, 0, $max);
        $last = (string) array_pop($lines);
        $lines[] = mb_substr($last, 0, $this->fitting($last, $size, $font, '…')).'…';

        return $lines;
    }

    /** How many leading characters of the text fit the column with the suffix after them; at least one. */
    private function fitting(string $text, float $size, string $font, string $suffix): int
    {
        $low = 1;
        $high = mb_strlen($text);
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->width(mb_substr($text, 0, $middle).$suffix, $size, $font) <= self::COLUMN) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    /** A logical line drawn right-aligned in the column, or centred. */
    private function draw(GdImage $image, string $line, float $size, bool $bold, int $baseline, int $color, bool $center = false): void
    {
        if ($line === '') {
            return;
        }
        $font = $this->font($bold);
        $visual = $this->visual($line);
        $box = imagettfbbox($size, 0, $font, $visual) ?: [0, 0, 0, 0, 0, 0, 0, 0];
        $width = $box[2] - $box[0];
        $x = $center ? intdiv(self::WIDTH - $width, 2) : self::SIDE + self::COLUMN - $width;
        imagettftext($image, $size, 0, $x - $box[0], $baseline, $color, $font, $visual);
    }

    private function width(string $line, float $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $this->visual($line));

        return $box === false ? PHP_INT_MAX : abs($box[2] - $box[0]);
    }

    /** Persian letters in their joined forms and the line in display order, as gd draws glyphs left to right. */
    private function visual(string $line): string
    {
        $font = ['subsetchars' => []];
        $visual = TCPDF_FONTS::utf8Bidi(TCPDF_FONTS::UTF8StringToArray($line, true, $font), '', true, true, $font);

        return TCPDF_FONTS::UTF8ArrSubString($visual, 0, count($visual), true);
    }

    private function font(bool $bold): string
    {
        return resource_path('fonts/vazirmatn/Vazirmatn-'.($bold ? 'Bold' : 'Regular').'.ttf');
    }

    private function color(GdImage $image, int $red, int $green, int $blue): int
    {
        return (int) imagecolorallocate($image, $red, $green, $blue);
    }
}
