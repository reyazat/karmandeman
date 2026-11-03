<?php

declare(strict_types=1);

namespace App\Helpers;

/** Ogg's page CRC-32: polynomial 0x04C11DB7, not reflected, zero start and no final XOR (RFC 3533). */
final class OggCrc
{
    /** @var list<int>|null */
    private static ?array $table = null;

    public static function of(string $page): int
    {
        $table = self::$table ??= self::table();
        $crc = 0;
        for ($i = 0, $size = strlen($page); $i < $size; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) ^ ord($page[$i])) & 0xFF];
        }

        return $crc;
    }

    /** A page with its CRC field (bytes 22 to 25) filled in. */
    public static function seal(string $page): string
    {
        $page = substr_replace($page, "\0\0\0\0", 22, 4);

        return substr_replace($page, pack('V', self::of($page)), 22, 4);
    }

    /** @return list<int> */
    private static function table(): array
    {
        $table = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $crc = $byte << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80000000) !== 0 ? ($crc << 1) ^ 0x04C11DB7 : $crc << 1) & 0xFFFFFFFF;
            }
            $table[] = $crc;
        }

        return $table;
    }
}
