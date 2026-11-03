<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Helpers\OggCrc;
use DomainException;
use finfo;

/** Checks the actual Ogg/Opus container and duration, not Telegram's metadata. */
final class OggOpusInspector
{
    /** @return array{mime: string, duration_ms: int} */
    public function inspect(#[\SensitiveParameter] string $bytes): array
    {
        $size = strlen($bytes);
        if ($size === 0 || $size > (int) config('karmandeman.voice.max_kilobytes') * 1024) {
            throw new DomainException('VOICE_TOO_LARGE');
        }
        if (! in_array((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), ['audio/ogg', 'application/ogg'], true)) {
            throw new DomainException('VOICE_UNSUPPORTED');
        }

        $offset = 0;
        $sequence = 0;
        $serial = null;
        $preSkip = null;
        $lastGranule = 0;
        $decodedSamples = 0;
        $packet = '';
        $packetIndex = 0;
        while ($offset < $size) {
            if ($size - $offset < 27 || substr($bytes, $offset, 4) !== 'OggS' || ord($bytes[$offset + 4]) !== 0) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $flags = ord($bytes[$offset + 5]);
            $segments = ord($bytes[$offset + 26]);
            if ($flags & ~0x07 || $size - $offset < 27 + $segments) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $lengths = substr($bytes, $offset + 27, $segments);
            $payloadLength = array_sum(array_values(unpack('C*', $lengths) ?: []));
            $pageSize = 27 + $segments + $payloadLength;
            if ($size - $offset < $pageSize
                || ($serial !== null && $serial !== substr($bytes, $offset + 14, 4))
                || (unpack('V', substr($bytes, $offset + 18, 4))[1] ?? -1) !== $sequence
                || ($sequence === 0 ? ($flags & 0x02) === 0 : ($flags & 0x02) !== 0)) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $page = substr($bytes, $offset, $pageSize);
            $expected = unpack('V', substr($page, 22, 4))[1];
            if ($expected !== OggCrc::of(substr_replace($page, "\0\0\0\0", 22, 4))) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $payload = substr($page, 27 + $segments);
            if ($sequence === 0) {
                if (strlen($payload) < 19 || ! str_starts_with($payload, 'OpusHead')
                    || ord($payload[8]) !== 1 || ! in_array(ord($payload[9]), [1, 2], true)
                    || $segments !== 1 || ord($lengths[0]) !== 19) {
                    throw new DomainException('VOICE_UNSUPPORTED');
                }
                $serial = substr($page, 14, 4);
                $preSkip = unpack('v', substr($payload, 10, 2))[1];
            }
            if ($sequence === 1 && ! str_starts_with($payload, 'OpusTags')) {
                throw new DomainException('VOICE_DAMAGED');
            }
            if (($flags & 0x01) !== ($packet === '' ? 0 : 1)) {
                throw new DomainException('VOICE_DAMAGED');
            }
            $payloadOffset = 0;
            $packetsEnded = $packetIndex;
            for ($i = 0; $i < $segments; $i++) {
                $length = ord($lengths[$i]);
                $packet .= substr($payload, $payloadOffset, $length);
                $payloadOffset += $length;
                if ($length < 255) {
                    if ($packetIndex > 1) {
                        $decodedSamples += $this->samplesInPacket($packet);
                        // The EOS granule can trim at most one 120 ms packet.
                        if ($decodedSamples > (int) config('karmandeman.voice.max_seconds') * 48000 + (int) $preSkip + 5760) {
                            throw new DomainException('VOICE_TOO_LONG');
                        }
                    }
                    $packetIndex++;
                    $packet = '';
                }
            }
            // A page that ends no packet has no position: granule -1 (RFC 3533).
            $granule = unpack('Vlow/Vhigh', substr($page, 6, 8));
            $unset = $granule['low'] === 0xFFFFFFFF && $granule['high'] === 0xFFFFFFFF;
            if ($unset && $packetIndex !== $packetsEnded) {
                throw new DomainException('VOICE_DAMAGED');
            }
            if (! $unset) {
                $lastGranule = $granule['low'] + $granule['high'] * 4294967296;
                if ($lastGranule > ((int) config('karmandeman.voice.max_seconds') * 48000 + (int) $preSkip)) {
                    throw new DomainException('VOICE_TOO_LONG');
                }
            }
            $offset += $pageSize;
            $sequence++;
            if (($flags & 0x04) !== 0 && $offset !== $size) {
                throw new DomainException('VOICE_DAMAGED');
            }
        }
        if ($sequence < 3 || $packetIndex < 3 || $packet !== '' || ($flags & 0x04) === 0
            || $lastGranule <= $preSkip || $lastGranule > $decodedSamples
            || $decodedSamples - $lastGranule > 5760 + $preSkip) {
            throw new DomainException('VOICE_DAMAGED');
        }

        return ['mime' => 'audio/ogg', 'duration_ms' => (int) ceil(($lastGranule - $preSkip) / 48)];
    }

    private function samplesInPacket(string $packet): int
    {
        if ($packet === '') {
            throw new DomainException('VOICE_DAMAGED');
        }
        $toc = ord($packet[0]);
        $config = $toc >> 3;
        $frames = match ($toc & 3) {
            0 => 1,
            1, 2 => 2,
            default => strlen($packet) < 2 ? 0 : ord($packet[1]) & 63,
        };
        $samples = $config < 12 ? [480, 960, 1920, 2880][$config & 3]
            : ($config < 16 ? [480, 960][$config & 1] : [120, 240, 480, 960][$config & 3]);
        if ($frames < 1 || $frames > 48 || $samples * $frames > 5760) {
            throw new DomainException('VOICE_DAMAGED');
        }

        return $samples * $frames;
    }
}
