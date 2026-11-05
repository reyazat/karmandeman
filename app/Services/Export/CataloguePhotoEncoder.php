<?php

declare(strict_types=1);

namespace App\Services\Export;

use GdImage;
use RuntimeException;

/**
 * A batch photo as the JPEG the catalogue embeds (ADR-052): decoded and
 * encoded again with gd, so no EXIF, GPS or other metadata of the original
 * reaches the file, and scaled down so five products stay a small download.
 * A JPEG sent as a file keeps the camera's orientation tag and is stored the
 * way the sensor read it; the tag is applied here, so the photo stands as it
 * did on the phone (ADR-056).
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
        $target = $this->upright($target, $size[2] === IMAGETYPE_JPEG ? $this->orientation($bytes) : 1);

        ob_start();
        imagejpeg($target, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /** The image turned and mirrored as EXIF orientation 1 to 8 asks; 1 or an unknown value leaves it. */
    private function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [3, 5, 6, 7, 8], true)) {
            // gd turns counter-clockwise: -90 is a quarter turn clockwise.
            $image = imagerotate($image, match ($orientation) {
                3 => 180,
                8 => 90,
                default => -90,
            }, 0) ?: $image;
        }
        match ($orientation) {
            2, 5 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4, 7 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $image;
    }

    /**
     * The orientation tag of a JPEG's EXIF block (IFD0 tag 0x0112), or 1 when
     * there is none or it cannot be read. Read by hand: gd keeps no metadata
     * and the exif extension is not in every image. Only the first APP1 Exif
     * segment before the image data counts; every offset is bounds-checked.
     */
    private function orientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        $position = 2;
        while ($position + 4 <= $length && $jpeg[$position] === "\xFF") {
            $marker = ord($jpeg[$position + 1]);
            // Start of scan or end of image: no EXIF block comes after it.
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }
            $size = unpack('n', $jpeg, $position + 2)[1];
            if ($size < 2 || $position + 2 + $size > $length) {
                return 1;
            }
            if ($marker === 0xE1 && substr($jpeg, $position + 4, 6) === "Exif\0\0") {
                return $this->tiffOrientation(substr($jpeg, $position + 10, $size - 8));
            }
            $position += 2 + $size;
        }

        return 1;
    }

    private function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = unpack($long, $tiff, 4)[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $entries = min(unpack($short, $tiff, $ifd)[1], intdiv(strlen($tiff) - $ifd - 2, 12));
        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifd + 2 + 12 * $index;
            if (unpack($short, $tiff, $entry)[1] === 0x0112) {
                $value = unpack($short, $tiff, $entry + 8)[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
