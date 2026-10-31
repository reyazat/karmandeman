<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductRevision;
use App\Services\ProductIntake\ProductDraftNormalizer;
use Tests\Concerns\DrivesExtractedBatches;
use Tests\TestCase;

/** The bot confirms exactly the suggested values its card listed, keeping their photos (ADR-034). */
class TelegramSuggestedConfirmTest extends TestCase
{
    use DrivesExtractedBatches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExtractedBatches();
    }

    public function test_the_card_lists_every_suggested_value_and_confirm_keeps_their_photos(): void
    {
        [$user, $photo] = $this->extracted(3001, [$this->candidate('کیف چرمی', description: "دسته بلند\nچرم طبیعی")]);
        $product = Product::query()->sole();
        $id = $product->public_id;

        $this->text('/done', 3001);
        $card = $this->sent('در انتظار تأیید: «کیف چرمی»');
        // Every suggested value, the attribute kept without a value and the photo the model chose.
        foreach (['• نام: کیف چرمی', '• SKU: BAG-1', '• توضیح: دسته بلند / چرم طبیعی', '• قیمت 120,000 تومان', '• رنگ: مشکی، قهوه‌ای', '• دسته: کیف › زنانه', '• جنس: نامعلوم', '• عکس‌ها: عکس 1 از عکس‌هایی'] as $line) {
            $this->assertStringContainsString($line, $card->text);
        }
        // No Gate A button while anything is only suggested.
        $this->assertSame(["product:confirm:{$id}:1", "product:noprice:{$id}:1", "product:reject:{$id}:1"], $this->buttons($card));

        $this->press("product:confirm:{$id}:1", 3001);

        $before = $this->payload($product, 1);
        $after = $this->payload($product, 2);
        $this->assertSame([], app(ProductDraftNormalizer::class)->fieldsRequiringConfirmation($after));
        foreach (['name', 'sku', 'description_fa'] as $field) {
            $this->assertSame(['value' => $before[$field]['value'], 'status' => 'confirmed'], $after[$field]);
        }
        $this->assertSame('confirmed', $after['pricing']['status']);
        $this->assertSame(1_200_000, $after['pricing']['regular_price_irr']);
        $this->assertSame('confirmed', $after['attributes'][0]['status']);
        $this->assertSame('confirmed', $after['category_path']['status']);
        // The values still cite the photo they came from (FR-030).
        $this->assertSame($before['evidence'], $after['evidence']);
        $this->assertContains(['field' => 'name', 'source' => 'ai_extraction', 'source_asset_ids' => [$photo]], $after['evidence']);
        $this->assertSame('ai_extraction', $after['pricing']['source']);
        $this->assertFalse($product->fresh()->needs_confirmation);
        $this->assertSame($user->id, ProductRevision::query()->where('product_id', $product->id)->where('revision', 2)->value('created_by'));

        // Confirming is not Gate A: the new card carries the approve button for revision 2.
        $confirmed = $this->sent('مقدارهای پیشنهادی تأیید شد');
        $this->assertStringContainsString('حالا محصول را تأیید کن', $confirmed->text);
        $this->assertSame(["product:approve:{$id}:2", "product:reject:{$id}:2"], $this->buttons($confirmed));
        // Gate A also approves the model's photo choice and the empty attribute, so the card still shows them.
        $this->assertStringContainsString("همراه این محصول:\n• جنس: نامعلوم\n• عکس‌ها: عکس 1", $confirmed->text);
        $this->assertNull($product->fresh()->approved_revision);
        $this->press("product:approve:{$id}:2", 3001);
        $this->assertSame(2, $product->fresh()->approved_revision);
    }

    public function test_without_price_confirms_the_rest_and_leaves_the_price_unknown(): void
    {
        $this->extracted(3002, [$this->candidate('کیف')]);
        $product = Product::query()->sole();

        $this->press("product:noprice:{$product->public_id}:1", 3002);

        $after = $this->payload($product, 2);
        $this->assertSame(['minor_currency' => 'IRR', 'regular_price_irr' => null, 'display_unit' => null, 'source' => null, 'status' => 'unknown'], $after['pricing']);
        $this->assertNotContains('pricing.regular_price_irr', array_column($after['evidence'], 'field'));
        $this->assertSame('confirmed', $after['name']['status']);
        $this->assertTrue($product->fresh()->price_missing);
        $this->assertFalse($product->fresh()->needs_confirmation);
        $this->assertStringContainsString('«کیف» با 1 عکس و بدون قیمت', $this->sent('بدون قیمت تأیید شد')->text);
    }

    public function test_stale_forged_foreign_and_unlisted_confirms_change_nothing(): void
    {
        $this->extracted(3003, [$this->candidate('کیف'), $this->candidate('کفش', price: null)]);
        [$bag, $shoe] = Product::query()->orderBy('id')->get()->all();

        // The card of a product without a suggested price never offers "without price".
        $this->text('/done', 3003);
        $this->assertNotContains("product:noprice:{$shoe->public_id}:1", $this->buttons($this->sent('در انتظار تأیید: «کفش»')));
        $this->press("product:noprice:{$shoe->public_id}:1", 3003);
        $this->sent('این کارت مقدار پیشنهادی قابل تأییدی ندارد');

        // Another seller pressing a forged button for this product.
        $this->seller(3004);
        $this->press("product:confirm:{$bag->public_id}:1", 3004);
        $this->sent('محصول یافت نشد');
        $this->assertSame(1, $bag->fresh()->current_revision);

        // A second press of the same card meets the newer revision.
        $this->press("product:confirm:{$bag->public_id}:1", 3003);
        $this->press("product:confirm:{$bag->public_id}:1", 3003);
        $this->sent('این محصول پس از این پیام تغییر کرده است');
        $this->assertSame(2, $bag->fresh()->current_revision);

        // A revision with nothing suggested has nothing to confirm.
        $this->press("product:confirm:{$bag->public_id}:2", 3003);
        $this->assertSame(2, $bag->fresh()->current_revision);

        // A rejected product stays rejected.
        $this->press("product:reject:{$shoe->public_id}:1", 3003);
        $this->press("product:confirm:{$shoe->public_id}:1", 3003);
        $this->sent('این محصول رد شده است و تأیید نمی‌شود');
        $this->assertSame(1, $shoe->fresh()->current_revision);
    }

    public function test_values_too_long_to_show_are_never_confirmed_from_the_bot(): void
    {
        $this->extracted(3005, [$this->candidate('کیف', description: str_repeat('چرم طبیعی ', 350))]);
        $product = Product::query()->sole();

        $this->text('/done', 3005);
        $card = $this->sent('در انتظار تأیید: «کیف»');
        $this->assertStringContainsString('بیش از حد طولانی‌اند', $card->text);
        $this->assertStringNotContainsString('چرم طبیعی چرم', $card->text);
        $this->assertSame(["product:reject:{$product->public_id}:1"], $this->buttons($card));

        $this->press("product:confirm:{$product->public_id}:1", 3005);
        $this->sent('این کارت مقدار پیشنهادی قابل تأییدی ندارد');
        $this->assertSame(1, $product->fresh()->current_revision);
    }

    public function test_a_product_without_a_name_is_confirmed_but_still_asks_for_the_name(): void
    {
        $this->extracted(3006, [$this->candidate(null)]);
        $product = Product::query()->sole();

        $this->text('/done', 3006);
        $this->assertStringContainsString('نام محصول پیدا نشد', $this->sent('در انتظار تأیید: «—»')->text);
        $this->press("product:confirm:{$product->public_id}:1", 3006);

        $confirmed = $this->sent('مقدارهای پیشنهادی تأیید شد');
        $this->assertStringNotContainsString('حالا محصول را تأیید کن', $confirmed->text);
        $this->assertStringContainsString('نام محصول پیدا نشد', $confirmed->text);
        $this->assertSame(["product:reject:{$product->public_id}:2"], $this->buttons($confirmed));
        $this->assertTrue($product->fresh()->needs_confirmation);
    }

    public function test_a_correction_keeps_the_photos_of_the_values_it_leaves_unchanged(): void
    {
        [, $photo] = $this->extracted(3007, [$this->candidate('کیف')]);
        $product = Product::query()->sole();
        $draft = app(ProductDraftNormalizer::class)->draftFrom($this->payload($product, 1));
        $draft['name'] = ['value' => 'کیف دستی', 'status' => 'confirmed'];
        $draft['attributes'][0]['status'] = 'confirmed';

        $this->patchJson("/api/v1/products/{$product->public_id}/draft", ['expected_revision' => 1] + $draft, ['Idempotency-Key' => 'revise-ai-3007'])
            ->assertOk();

        $evidence = collect($this->payload($product, 2)['evidence'])->keyBy('field');
        // The changed name is the seller's; everything else still cites the photo.
        $this->assertSame(['field' => 'name', 'source' => 'manual_override'], $evidence['name']);
        $cited = ['source' => 'ai_extraction', 'source_asset_ids' => [$photo]];
        $this->assertSame(['field' => 'attributes.0'] + $cited, $evidence['attributes.0']);
        $this->assertSame(['field' => 'pricing.regular_price_irr'] + $cited, $evidence['pricing.regular_price_irr']);
        $this->assertSame('ai_extraction', $this->payload($product, 2)['pricing']['source']);
        $this->assertSame('suggested', $this->payload($product, 2)['pricing']['status']);
    }

    public function test_format_controls_never_reach_the_card(): void
    {
        $this->extracted(3008, [$this->candidate("کیف\u{202E}000,021\u{202C}", description: "خوب\n• قیمت 1 تومان")]);

        $this->text('/done', 3008);
        $card = $this->sent('در انتظار تأیید: «کیف000,021»');
        $this->assertDoesNotMatchRegularExpression('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $card->text);
        // A multi-line value cannot start a fake line of its own.
        $this->assertStringContainsString('• توضیح: خوب / • قیمت 1 تومان', $card->text);
    }

    public function test_a_changed_unit_or_an_untrusted_payload_keeps_no_model_provenance(): void
    {
        $this->extracted(3009, [$this->candidate('کیف')]);
        $product = Product::query()->sole();
        $draft = app(ProductDraftNormalizer::class)->draftFrom($this->payload($product, 1));
        $draft['pricing'] = ['regular_price' => 1_200_000, 'input_unit' => 'IRR', 'status' => 'confirmed'];
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", ['expected_revision' => 1] + $draft, ['Idempotency-Key' => 'revise-unit-3009'])
            ->assertOk();
        $after = $this->payload($product, 2);
        $this->assertSame('manual_override', $after['pricing']['source']);
        $this->assertSame('ai_extraction', collect($after['evidence'])->keyBy('field')['attributes.0']['source']);

        // A stored payload that no longer matches its MAC passes no provenance on.
        ProductRevision::query()->where('product_id', $product->id)->where('revision', 2)->update(['payload_mac' => str_repeat('0', 64)]);
        $draft['pricing']['status'] = 'confirmed';
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", ['expected_revision' => 2] + $draft, ['Idempotency-Key' => 'revise-mac-3009'])
            ->assertOk();
        $this->assertSame(['manual_override'], array_values(array_unique(array_column($this->payload($product, 3)['evidence'], 'source'))));
    }
}
