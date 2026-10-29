<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductIntake\PersianTextNormalizer;
use App\Services\ProductIntake\ProductDraftNormalizer;
use PHPUnit\Framework\TestCase;

/** A correction starts from the stored payload's draft and keeps every other field (ADR-029). */
class ProductDraftRoundTripTest extends TestCase
{
    public function test_the_draft_of_a_payload_normalizes_back_to_the_same_payload(): void
    {
        $normalizer = new ProductDraftNormalizer(new PersianTextNormalizer);
        $drafts = [
            [
                'name' => ['value' => 'كيف  چرم', 'status' => 'confirmed'],
                'sku' => ['value' => ' sk-١٢ ', 'status' => 'suggested'],
                'description_fa' => ['value' => "چرم\n\nطبیعی", 'status' => 'confirmed'],
                'pricing' => ['regular_price' => 120_000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
                'attributes' => [
                    ['name' => 'رنگ ', 'values' => ['مشكی', 'قهوه‌ای'], 'status' => 'suggested'],
                    ['name' => 'سایز', 'values' => null],
                ],
                'category_path' => ['value' => ['كیف', 'زنانه'], 'status' => 'confirmed'],
                'image_asset_ids' => ['0193F0A4-8C2B-7000-8000-000000000001'],
            ],
            ['name' => ['value' => 'شال', 'status' => 'confirmed'], 'pricing' => ['regular_price' => 95_000, 'input_unit' => 'IRR', 'status' => 'suggested']],
            ['name' => ['value' => 'بدون قیمت', 'status' => 'confirmed']],
        ];

        foreach ($drafts as $draft) {
            $payload = $normalizer->normalize($draft);
            $this->assertSame($payload, $normalizer->normalize($normalizer->draftFrom($payload)));
        }
    }
}
