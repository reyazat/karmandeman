<?php

declare(strict_types=1);

namespace Tests\Fakes;

/** 200 ms of silence, generated with ffmpeg/libopus; no seller data. */
final class OggFixture
{
    public static function make(): string
    {
        return base64_decode(
            'T2dnUwACAAAAAAAAAAAJH3EcAAAAADp+H+IBE09wdXNIZWFkAQE4AYC7AAAAAABPZ2dTAAAAAAAAAAAAAAkfcRwBAAAAaI+SPgE+T3B1c1RhZ3MNAAAATGF2ZjYwLjE2LjEwMAEAAAAdAAAAZW5jb2Rlcj1MYXZjNjAuMzEuMTAyIGxpYm9wdXNPZ2dTAAS4JgAAAAAAAAkfcRwCAAAA231GqAsOEA8PDw8PDw8PD2gL5ME27MWNjElIQmzQaAfJcifhROpV8fDA0TIpMGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcGgHyXnIyVfAohIj+lkgcA==',
            true,
        );
    }

    /**
     * The same voice with one extra 20 ms packet first, 300 bytes long and
     * split so that the first audio page ends no packet. Such a page has
     * granule -1, as libogg writes for large packets: 220 ms in all.
     */
    public static function withPageEndingNoPacket(): string
    {
        $voice = self::make();
        $headers = substr($voice, 0, 47 + 90);
        $last = substr($voice, 47 + 90);
        $serial = substr($last, 14, 4);
        $segments = ord($last[26]);
        $lengths = substr($last, 27, $segments);
        $audio = substr($last, 27 + $segments);
        $granule = unpack('P', substr($last, 6, 8))[1] + 960;
        $packet = $audio[0].str_repeat("\x55", 299);

        return $headers
            .self::page(0x00, -1, $serial, 2, chr(255), substr($packet, 0, 255))
            .self::page(0x05, $granule, $serial, 3, chr(45).$lengths, substr($packet, 255).$audio);
    }

    /**
     * The same voice with a comment header over two pages that names a title
     * and the recording app, as a phone recorder might write.
     */
    public static function withLongTags(): string
    {
        $voice = self::make();
        $first = substr($voice, 0, 47);
        $serial = substr($voice, 14, 4);
        $audio = substr($voice, 47 + 90);
        $comments = ['TITLE=Seller private note', 'ENCODER=PhoneRecorder 9.1', 'COMMENT='.str_repeat('x', 400)];
        $tags = 'OpusTags'.pack('V', 7).'Vendor1'.pack('V', count($comments));
        foreach ($comments as $comment) {
            $tags .= pack('V', strlen($comment)).$comment;
        }
        $rest = strlen($tags) - 255;

        return $first
            .self::page(0x00, 0, $serial, 1, chr(255), substr($tags, 0, 255))
            .self::page(0x01, 0, $serial, 2, str_repeat(chr(255), intdiv($rest, 255)).chr($rest % 255), substr($tags, 255))
            .self::page(ord($audio[5]), unpack('P', substr($audio, 6, 8))[1], $serial, 3, substr($audio, 27, ord($audio[26])), substr($audio, 27 + ord($audio[26])));
    }

    private static function page(int $flags, int $granule, string $serial, int $sequence, string $lengths, string $payload): string
    {
        $page = 'OggS'."\0".chr($flags).pack('P', $granule).$serial.pack('V', $sequence)
            ."\0\0\0\0".chr(strlen($lengths)).$lengths.$payload;

        return substr_replace($page, pack('V', self::crc($page)), 22, 4);
    }

    private static function crc(string $page): int
    {
        $crc = 0;
        foreach (str_split($page) as $byte) {
            $crc ^= ord($byte) << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80000000) !== 0 ? ($crc << 1) ^ 0x04C11DB7 : $crc << 1) & 0xFFFFFFFF;
            }
        }

        return $crc;
    }
}
