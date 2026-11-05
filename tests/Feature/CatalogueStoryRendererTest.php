<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Export\CataloguePhotoEncoder;
use App\Services\Export\CatalogueStoryRenderer;
use App\Services\Export\CatalogueText;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** The fixed 9:16 story image of one approved product (ADR-053). */
final class CatalogueStoryRendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(string $name, ?int $irr, ?string $description = 'کیف برزنتی مشکی'): array
    {
        return [
            'name' => ['value' => $name, 'status' => 'confirmed'],
            'description_fa' => ['value' => $description, 'status' => 'confirmed'],
            'pricing' => ['regular_price_irr' => $irr, 'display_unit' => $irr === null ? null : 'toman'],
            'image_asset_ids' => [],
        ];
    }

    public function test_a_story_is_a_1080_by_1920_jpeg_with_or_without_a_photo(): void
    {
        $renderer = app(CatalogueStoryRenderer::class);
        foreach ([[300, 600], [1600, 400]] as [$width, $height]) {
            $photo = app(CataloguePhotoEncoder::class)->jpeg(ImageFixture::make(4, 'png', $width, $height));
            $story = $renderer->render('فروشگاه نسترن', 'مجموعه آبان', $this->payload('کیف روزمره', 3_500_000), $photo);
            $this->assertSame([1080, 1920, IMAGETYPE_JPEG], array_slice((array) getimagesizefromstring($story), 0, 3));
            $this->assertStringNotContainsString('Exif', $story);
        }

        $bare = $renderer->render('برند', 'مجموعه', $this->payload('شال', null, null), null);
        $this->assertSame([1080, 1920], array_slice((array) getimagesizefromstring($bare), 0, 2));
    }

    public function test_long_text_ends_in_two_lines_and_the_price_is_never_cut(): void
    {
        $renderer = app(CatalogueStoryRenderer::class);
        $payload = $this->payload(str_repeat('کیف دستی زنانه با نام بسیار بلند ', 12), 9_999_999_999_990, str_repeat('توضیح طولانی ', 200));

        $lines = $renderer->lines($payload);

        $this->assertCount(2, $lines['name']);
        $this->assertStringEndsWith('…', $lines['name'][1]);
        $this->assertCount(2, $lines['description']);
        $this->assertStringEndsWith('…', $lines['description'][1]);
        // The same price text as the PDF, whole, at a size that fits.
        $this->assertSame(app(CatalogueText::class)->price($payload), $lines['price']);
        $this->assertSame('قیمت: ۹۹۹٬۹۹۹٬۹۹۹٬۹۹۹ تومان', $lines['price']);
        $this->assertLessThanOrEqual(42.0, $lines['price_size']);
    }

    public function test_short_text_is_kept_whole_and_controls_are_removed(): void
    {
        $lines = app(CatalogueStoryRenderer::class)->lines($this->payload("کیف\u{202E} روزمره", null, "خط اول\nخط دوم"));

        $this->assertSame(['کیف روزمره'], $lines['name']);
        $this->assertSame(['خط اول / خط دوم'], $lines['description']);
        $this->assertSame('قیمت: از فروشنده بپرسید', $lines['price']);
    }

    public function test_one_word_wider_than_the_line_is_broken(): void
    {
        $lines = app(CatalogueStoryRenderer::class)->lines($this->payload(str_repeat('ب', 60), 10, null));

        $this->assertCount(2, $lines['name']);
        $this->assertSame(str_repeat('ب', 60), implode('', $lines['name']));
    }
}
