<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;

/**
 * A batch photo as the JPEG the catalogue embeds (ADR-052): decoded and
 * encoded again with gd, so no EXIF, GPS or other metadata of the original
 * reaches the file, and scaled down so five products stay a small download.
 */
final readonly class CataloguePhotoEncoder
{
    /** Longest side in pixels: sharp at the 180 mm photo box. */
    private const MAX_SIDE = 1600;

    private const QUALITY = 82;

    /** @throws RuntimeException when gd cannot read the photo or it is larger than an upload may be */
    public function jpeg(#[\SensitiveParameter] string $bytes): string
    {
        // Checked before decoding: a decoded photo takes about 5 bytes a pixel, and running
        // out of memory is a fatal error no retry or release would follow.
        $size = @getimagesizefromstring($bytes);
        if ($size === false || (int) config('karmandeman.assets.max_pixels') < $size[0] * $size[1]) {
            throw new RuntimeException('Photo could not be decoded.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Photo could not be decoded.');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_SIDE / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        // A transparent PNG or WebP lies on white, not black.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        ob_start();
        imagejpeg($target, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
