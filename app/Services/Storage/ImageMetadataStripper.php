<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\ImageMetadataUnparseable;

/**
 * Removes metadata from a stored photo before it leaves Laravel (ADR-022,
 * ADR-033). Every format keeps only an allowlist of image structures, so
 * EXIF (with GPS), XMP, IPTC, C2PA, comments, text chunks, previews and any
 * bytes after the image end are dropped. Works on the container structure
 * only, so it needs no image library and never decodes pixels. Anything it
 * cannot parse is refused instead of sent as it is.
 */
final class ImageMetadataStripper
{
    /** PNG chunks that describe pixels, colour or animation; every other chunk goes. */
    private const PNG_KEPT = [
        'IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'bKGD', 'pHYs',
        'cICP', 'acTL', 'fcTL', 'fdAT',
    ];

    /** WebP chunks of the image itself; EXIF, XMP, C2PA and unknown chunks go. */
    private const WEBP_KEPT = ['VP8 ', 'VP8L', 'VP8X', 'ALPH', 'ICCP', 'ANIM', 'ANMF'];

    /** @throws ImageMetadataUnparseable */
    public function strip(string $mime, #[\SensitiveParameter] string $bytes): string
    {
        return match ($mime) {
            'image/jpeg' => $this->jpeg($bytes),
            'image/png' => $this->png($bytes),
            'image/webp' => $this->webp($bytes),
            default => throw new ImageMetadataUnparseable,
        };
    }

    private function jpeg(string $bytes): string
    {
        $length = strlen($bytes);
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            throw new ImageMetadataUnparseable;
        }
        $out = "\xFF\xD8";
        $i = 2;
        while ($i < $length) {
            if ($bytes[$i] !== "\xFF") {
                throw new ImageMetadataUnparseable;
            }
            while ($i < $length && $bytes[$i] === "\xFF") {
                $i++;
            }
            if ($i >= $length) {
                throw new ImageMetadataUnparseable;
            }
            $marker = ord($bytes[$i]);
            $i++;
            if ($marker === 0xD9) {
                // End of image: whatever follows (a polyglot tail) is dropped.
                return $out."\xFF\xD9";
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= "\xFF".chr($marker);

                continue;
            }
            if ($i + 2 > $length) {
                throw new ImageMetadataUnparseable;
            }
            $size = unpack('n', substr($bytes, $i, 2))[1];
            if ($size < 2 || $i + $size > $length) {
                throw new ImageMetadataUnparseable;
            }
            $segment = substr($bytes, $i, $size);
            $i += $size;
            if ($marker === 0xDA) {
                $end = $this->jpegScanEnd($bytes, $i);
                $out .= "\xFF\xDA".$segment.substr($bytes, $i, $end - $i);
                $i = $end;

                continue;
            }
            $kept = $this->jpegKept($marker, substr($segment, 2));
            if ($kept !== null) {
                $out .= "\xFF".chr($marker).pack('n', strlen($kept) + 2).$kept;
            }
        }

        throw new ImageMetadataUnparseable;
    }

    /** First marker after entropy-coded data: not a stuffed 0x00, a restart or fill byte. */
    private function jpegScanEnd(string $bytes, int $from): int
    {
        $position = $from;
        while (($found = strpos($bytes, "\xFF", $position)) !== false) {
            $next = $bytes[$found + 1] ?? null;
            if ($next === null) {
                break;
            }
            $code = ord($next);
            if ($code === 0x00 || $code === 0xFF || ($code >= 0xD0 && $code <= 0xD7)) {
                $position = $found + ($code === 0xFF ? 1 : 2);

                continue;
            }

            return $found;
        }

        throw new ImageMetadataUnparseable;
    }

    /**
     * The segment data to keep, or null to drop it. Only frame, table and
     * restart segments stay as they are. JFIF and Adobe keep their fixed
     * header (no thumbnail, nothing appended) and ICC its colour profile, as
     * decoders need them. Every other APPn, COM, JPGn and unknown marker goes.
     */
    private function jpegKept(int $marker, string $data): ?string
    {
        return match (true) {
            // SOF0-15 except JPG (0xC8), DHT (0xC4) and DAC (0xCC) are all in this range.
            $marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC8 => $data,
            // DQT, DNL, DRI.
            $marker === 0xDB || $marker === 0xDC || $marker === 0xDD => $data,
            // Version, units and densities, with the thumbnail set to 0x0.
            $marker === 0xE0 && str_starts_with($data, "JFIF\x00") && strlen($data) >= 14 => substr($data, 0, 12)."\x00\x00",
            $marker === 0xE2 && str_starts_with($data, "ICC_PROFILE\x00") => $data,
            // Version, two flag words and the colour transform.
            $marker === 0xEE && str_starts_with($data, 'Adobe') && strlen($data) >= 12 => substr($data, 0, 12),
            default => null,
        };
    }

    private function png(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (! str_starts_with($bytes, $signature)) {
            throw new ImageMetadataUnparseable;
        }
        $length = strlen($bytes);
        $out = $signature;
        $i = 8;
        while ($i + 12 <= $length) {
            $size = unpack('N', substr($bytes, $i, 4))[1];
            $type = substr($bytes, $i + 4, 4);
            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1 || $i + 12 + $size > $length) {
                throw new ImageMetadataUnparseable;
            }
            $chunk = substr($bytes, $i, 12 + $size);
            $i += 12 + $size;
            if (in_array($type, self::PNG_KEPT, true)) {
                $out .= $chunk;
            }
            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new ImageMetadataUnparseable;
    }

    private function webp(string $bytes): string
    {
        if (strlen($bytes) < 12 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw new ImageMetadataUnparseable;
        }
        $end = 8 + unpack('V', substr($bytes, 4, 4))[1];
        if ($end > strlen($bytes)) {
            throw new ImageMetadataUnparseable;
        }
        $body = 'WEBP';
        $i = 12;
        while ($i < $end) {
            if ($i + 8 > $end) {
                throw new ImageMetadataUnparseable;
            }
            $fourcc = substr($bytes, $i, 4);
            $size = unpack('V', substr($bytes, $i + 4, 4))[1];
            if ($i + 8 + $size > $end) {
                throw new ImageMetadataUnparseable;
            }
            $data = substr($bytes, $i + 8, $size);
            $i += 8 + $size + ($size % 2);
            if (! in_array($fourcc, self::WEBP_KEPT, true)) {
                continue;
            }
            if ($fourcc === 'VP8X' && $size >= 1) {
                // The canvas header must no longer announce the removed EXIF and XMP chunks.
                $data[0] = chr(ord($data[0]) & ~0x0C);
            }
            $body .= $fourcc.pack('V', $size).$data.($size % 2 === 1 ? "\x00" : '');
        }
        if ($body === 'WEBP') {
            throw new ImageMetadataUnparseable;
        }

        // Bytes after the RIFF container are dropped.
        return 'RIFF'.pack('V', strlen($body)).$body;
    }
}
