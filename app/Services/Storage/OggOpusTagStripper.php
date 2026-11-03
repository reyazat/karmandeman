<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Helpers\OggCrc;
use DomainException;

/**
 * Removes the comment header of a stored voice before it leaves Laravel
 * (ADR-047), as ADR-033 does for photo metadata. The OpusTags packet can
 * carry the recording app, a title, a date or cover art; it is replaced
 * with an empty one. The identification header and every audio page stay
 * byte for byte, renumbered and resealed after the new comment page. The
 * voice was checked on intake (OggOpusInspector); a layout this does not
 * expect is refused instead of sent as it is.
 */
final class OggOpusTagStripper
{
    /** An empty comment header: no vendor string and no comments (RFC 7845 §5.2). */
    private const EMPTY_TAGS = "OpusTags\0\0\0\0\0\0\0\0";

    /** @throws DomainException VOICE_DAMAGED */
    public function strip(#[\SensitiveParameter] string $bytes): string
    {
        $pages = $this->pages($bytes);
        // The identification header is alone on the first page; the comment header starts the second
        // and ends its last page, so the audio starts on a fresh page (RFC 7845 §3).
        $tagsEnd = 1;
        while ($tagsEnd < count($pages) && $this->endsNoPacket($pages[$tagsEnd])) {
            $tagsEnd++;
        }
        if (count($pages) < 3 || $tagsEnd >= count($pages) - 1 || ! str_starts_with(substr($pages[1], 27 + ord($pages[1][26])), 'OpusTags')) {
            throw new DomainException('VOICE_DAMAGED');
        }
        for ($i = 1; $i <= $tagsEnd; $i++) {
            $lengths = substr($pages[$i], 27, ord($pages[$i][26]));
            // Only one packet on the comment pages: every lacing value but the very last is 255.
            if (strlen($lengths) === 0 || rtrim(substr($lengths, 0, -1), "\xFF") !== '' || ($i === $tagsEnd && ord($lengths[-1]) === 255)) {
                throw new DomainException('VOICE_DAMAGED');
            }
        }

        $first = $pages[1];
        $out = $pages[0].OggCrc::seal('OggS'."\0\0".str_repeat("\0", 8).substr($first, 14, 4).pack('V', 1)
            ."\0\0\0\0".chr(1).chr(strlen(self::EMPTY_TAGS)).self::EMPTY_TAGS);
        $sequence = 2;
        foreach (array_slice($pages, $tagsEnd + 1) as $page) {
            $out .= OggCrc::seal(substr_replace($page, pack('V', $sequence++), 18, 4));
        }

        return $out;
    }

    /** @return list<string> */
    private function pages(string $bytes): array
    {
        $pages = [];
        for ($offset = 0, $size = strlen($bytes); $offset < $size;) {
            if ($size - $offset < 27 || substr($bytes, $offset, 4) !== 'OggS') {
                throw new DomainException('VOICE_DAMAGED');
            }
            $segments = ord($bytes[$offset + 26]);
            if ($size - $offset < 27 + $segments) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $length = 27 + $segments + array_sum(array_values(unpack('C*', substr($bytes, $offset + 27, $segments)) ?: []));
            if ($size - $offset < $length) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $pages[] = substr($bytes, $offset, $length);
            $offset += $length;
        }

        return $pages;
    }

    private function endsNoPacket(string $page): bool
    {
        $segments = ord($page[26]);

        return $segments === 0 || ord($page[26 + $segments]) === 255;
    }
}
