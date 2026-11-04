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
}
