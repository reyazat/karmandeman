<?php

declare(strict_types=1);

namespace Tests\Fakes;

/**
 * Image bytes without GD (the dev image has none). The PNG is fully valid; the
 * JPEG and WebP carry real headers and dimensions with a placeholder body,
 * which is all the upload path inspects (ADR-022). The seed changes the bytes.
 */
final class ImageFixture
{
    public static function make(int $seed, string $type = 'jpeg', int $width = 40, int $height = 30): string
    {
        return match ($type) {
            'png' => self::png($seed, $width, $height),
            'webp' => self::webp($seed, $width, $height),
            'gif' => 'GIF89a'.pack('vv', $width, $height)."\x00\x00\x00".str_repeat(chr($seed % 256), 16).';',
            default => self::jpeg($seed, $width, $height),
        };
    }

    private static function png(int $seed, int $width, int $height): string
    {
        $row = "\x00".str_repeat(chr($seed % 256).chr(intdiv($seed, 256) % 256)."\x5a", $width);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }

    private static function jpeg(int $seed, int $width, int $height): string
    {
        $segment = fn (string $marker, string $data): string => "\xFF".$marker.pack('n', strlen($data) + 2).$data;

        return "\xFF\xD8"
            .$segment("\xE0", "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
            .$segment("\xFE", "seed {$seed}")
            .$segment("\xC0", "\x08".pack('nn', $height, $width)."\x01\x01\x11\x00")
            .$segment("\xDA", "\x01\x01\x00\x00\x3F\x00")
            .str_repeat(chr(($seed % 200) + 1), 64)
            ."\xFF\xD9";
    }

    private static function webp(int $seed, int $width, int $height): string
    {
        $vp8l = "\x2F".pack('V', ($width - 1) | (($height - 1) << 14)).str_repeat(chr($seed % 256), 8);
        $body = 'WEBP'.'VP8L'.pack('V', strlen($vp8l)).$vp8l;

        return 'RIFF'.pack('V', strlen($body)).$body;
    }
}
