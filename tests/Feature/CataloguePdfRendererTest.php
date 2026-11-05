<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Export\CataloguePdfRenderer;
use App\Services\Export\CataloguePhotoEncoder;
use RuntimeException;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** The fixed catalogue layout and its photos (ADR-052). */
final class CataloguePdfRendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(string $name, ?int $irr, ?string $unit = 'toman'): array
    {
        return [
            'name' => ['value' => $name, 'status' => 'confirmed'],
            'sku' => ['value' => 'BAG-1', 'status' => 'confirmed'],
            'description_fa' => ['value' => "خط اول\nخط دوم", 'status' => 'confirmed'],
            'pricing' => ['regular_price_irr' => $irr, 'display_unit' => $irr === null ? null : $unit],
            'attributes' => [['name' => 'جنس', 'values' => ['برزنت'], 'status' => 'confirmed'], ['name' => 'وزن', 'values' => null, 'status' => 'unknown']],
            'category_path' => ['value' => ['کیف'], 'status' => 'confirmed'],
            'image_asset_ids' => [],
        ];
    }

    public function test_one_page_per_product_with_or_without_a_photo_or_price(): void
    {
        $photo = app(CataloguePhotoEncoder::class)->jpeg(ImageFixture::make(5, 'png', 300, 600));

        $pdf = app(CataloguePdfRenderer::class)->render('فروشگاه نسترن', 'مجموعه آبان', 3, [
            ['payload' => $this->payload('کیف روزمره', 3_500_000), 'photo' => $photo],
            ['payload' => $this->payload(str_repeat('کیف دستی با نام بسیار بلند ', 8), null), 'photo' => null],
            ['payload' => $this->payload("<b>شال</b>\u{202E}", 1_250_000, 'rial'), 'photo' => $photo],
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(3, preg_match_all('#/Type /Page[^s]#', $pdf));
        // The font is embedded, so the file reads the same on any phone.
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertSame(2, substr_count($pdf, '/Subtype /Image'));
    }

    public function test_a_catalogue_needs_a_product(): void
    {
        $this->expectException(RuntimeException::class);

        app(CataloguePdfRenderer::class)->render('برند', 'مجموعه', 1, []);
    }

    public function test_the_embedded_photo_carries_no_metadata_and_is_scaled_down(): void
    {
        $png = ImageFixture::make(9, 'png', 2400, 1200);
        // A text chunk before IEND, as a camera or editor might leave one.
        $text = 'GPS'."\0".'35.7,51.4';
        $tagged = substr($png, 0, -12).pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text)).substr($png, -12);
        $this->assertNotFalse(@imagecreatefromstring($tagged));

        $jpeg = app(CataloguePhotoEncoder::class)->jpeg($tagged);

        $this->assertStringStartsWith("\xFF\xD8", $jpeg);
        $this->assertStringNotContainsString('35.7,51.4', $jpeg);
        $this->assertStringNotContainsString('Exif', $jpeg);
        $this->assertSame([1600, 800], array_slice((array) getimagesizefromstring($jpeg), 0, 2));
    }

    public function test_an_unreadable_photo_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(CataloguePhotoEncoder::class)->jpeg('not an image');
    }

    public function test_product_text_is_never_a_page_number_alias(): void
    {
        $pdf = app(CataloguePdfRenderer::class)->render('برند {:ptp:}', 'مجموعه', 1, [
            ['payload' => $this->payload('کیف {:pnp:} }:ptg:{', 10), 'photo' => null],
        ]);

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $content = implode('', array_filter(array_map(fn (string $stream): string|false => @gzuncompress($stream), $streams[1])));
        // The seller's text is written as typed, not replaced by a page count or number.
        foreach ([':ptp:', ':pnp:', ':ptg:'] as $alias) {
            $this->assertStringContainsString(mb_convert_encoding($alias, 'UTF-16BE', 'UTF-8'), $content);
        }
    }

    /** A 40×20 JPEG, red on the left and blue on the right, with an EXIF orientation tag when given. */
    private function taggedJpeg(?int $orientation, string $order = 'II'): string
    {
        $image = imagecreatetruecolor(40, 20);
        imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();
        if ($orientation === null) {
            return $jpeg;
        }
        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $tiff = $order.pack($short, 42).pack($long, 8).pack($short, 1)
            .pack($short, 0x0112).pack($short, 3).pack($long, 1).pack($short, $orientation).pack($short, 0).pack($long, 0);

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', 8 + strlen($tiff))."Exif\0\0".$tiff.substr($jpeg, 2);
    }

    /** @return array{int, int, string} width, height and whether the top-left corner is red or blue */
    private function upright(string $jpeg): array
    {
        $image = imagecreatefromstring(app(CataloguePhotoEncoder::class)->jpeg($jpeg));
        $this->assertNotFalse($image);
        $rgb = imagecolorat($image, 2, 2);

        return [imagesx($image), imagesy($image), (($rgb >> 16) & 0xFF) > ($rgb & 0xFF) ? 'red' : 'blue'];
    }

    public function test_a_photo_stands_as_its_orientation_tag_says(): void
    {
        $this->assertSame([40, 20, 'red'], $this->upright($this->taggedJpeg(null)));
        $this->assertSame([40, 20, 'red'], $this->upright($this->taggedJpeg(1)));
        // A quarter turn clockwise: the left side becomes the top.
        $this->assertSame([20, 40, 'red'], $this->upright($this->taggedJpeg(6)));
        $this->assertSame([20, 40, 'red'], $this->upright($this->taggedJpeg(6, 'MM')));
        $this->assertSame([20, 40, 'blue'], $this->upright($this->taggedJpeg(8)));
        $this->assertSame([40, 20, 'blue'], $this->upright($this->taggedJpeg(3)));
        $this->assertSame([40, 20, 'blue'], $this->upright($this->taggedJpeg(2)));
        $this->assertSame([40, 20, 'red'], $this->upright($this->taggedJpeg(4)));
        // Mirrored and turned: transpose keeps red top-left, transverse ends blue there.
        $this->assertSame([20, 40, 'red'], $this->upright($this->taggedJpeg(5)));
        $this->assertSame([20, 40, 'blue'], $this->upright($this->taggedJpeg(7)));
        // An unknown value or a cut EXIF block changes nothing.
        $this->assertSame([40, 20, 'red'], $this->upright($this->taggedJpeg(9)));
        $tagged = $this->taggedJpeg(6);
        $this->assertSame([40, 20, 'red'], $this->upright(substr($tagged, 0, 4).pack('n', 9).substr($tagged, 6)));
    }
}
