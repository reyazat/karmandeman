<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Services\ProductIntake\ProductDraftNormalizer;
use App\Services\Telegram\TelegramProductCorrection;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesExtractedBatches;
use Tests\TestCase;

/** "label: value" replies to a product card correct any field, and only that field (ADR-051). */
final class TelegramFieldCorrectionTest extends TestCase
{
    use DrivesExtractedBatches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExtractedBatches();
    }

    /** @return array<string, mixed> */
    private function candidate(string $name = 'کیف چرمی', ?string $sku = 'BAG-1', ?array $price = ['amount' => 120000, 'unit' => 'IRT'], ?array $attributes = null): array
    {
        return [
            'name' => $name, 'sku' => $sku, 'description_fa' => null, 'price' => $price,
            'attributes' => $attributes ?? [['name' => 'رنگ', 'values' => ['مشکی', 'قهوه‌ای']], ['name' => 'جنس', 'values' => ['چرم']]],
            'category_path' => ['کیف', 'زنانه'],
        ];
    }

    private function sent(string $fragment): SendTelegramMessage
    {
        $job = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, "No bot message contains «{$fragment}».");

        return $job;
    }

    private function lastReply(): string
    {
        return Queue::pushed(SendTelegramMessage::class)->last()->text;
    }

    /** A text in reply to this bot card, as Telegram delivers it. */
    private function replyTo(SendTelegramMessage $card, string $text, int $telegramId): void
    {
        $this->update(['message' => [
            'message_id' => ++$this->updates, 'date' => time(), 'text' => $text,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
            'reply_to_message' => [
                'message_id' => 900, 'date' => time(), 'text' => $card->text,
                'from' => ['id' => 123, 'is_bot' => true, 'first_name' => 'Karmandeman'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'reply_markup' => ['inline_keyboard' => $card->buttons],
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $revision): array
    {
        return ProductRevision::query()->where('product_id', $product->id)->where('revision', $revision)->sole()->payload;
    }

    /** @return array<string, mixed> */
    private function attribute(array $payload, string $name): ?array
    {
        foreach ($payload['attributes'] as $attribute) {
            if ($attribute['name'] === $name) {
                return $attribute;
            }
        }

        return null;
    }

    public function test_a_labelled_reply_changes_only_the_named_fields_and_keeps_the_rest_suggested(): void
    {
        [, $photo] = $this->extracted(3101, [$this->candidate()]);
        $product = Product::query()->sole();
        $this->text('/done', 3101);
        $card = $this->sent('در انتظار تأیید: «کیف چرمی»');
        $this->assertStringContainsString('«جنس: برزنت»', $card->text);

        $this->replyTo($card, "جنس: برزنت\nتوضیح: کیف روزمره سبک\nقیمت: نامعلوم", 3101);

        $before = $this->payload($product, 1);
        $after = $this->payload($product, 2);
        $this->assertSame(['name' => 'جنس', 'values' => ['برزنت'], 'status' => 'confirmed'], $this->attribute($after, 'جنس'));
        $this->assertSame(['value' => 'کیف روزمره سبک', 'status' => 'confirmed'], $after['description_fa']);
        $this->assertSame(['minor_currency' => 'IRR', 'regular_price_irr' => null, 'display_unit' => null, 'source' => null, 'status' => 'unknown'], $after['pricing']);
        // Fields not named stay as they were: still suggested and still citing the photo.
        foreach (['name', 'sku', 'category_path'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame($this->attribute($before, 'رنگ'), $this->attribute($after, 'رنگ'));
        $this->assertContains(['field' => 'name', 'source' => 'ai_extraction', 'source_asset_ids' => [$photo]], $after['evidence']);
        $this->assertSame(['manual_override'], array_column(array_filter($after['evidence'], fn (array $entry): bool => $entry['field'] === 'description_fa'), 'source'));
        $this->assertNotContains('pricing.regular_price_irr', array_column($after['evidence'], 'field'));
        $this->assertTrue($product->fresh()->price_missing);

        // The new card shows the corrected values and still asks to confirm what is only suggested.
        $revised = $this->sent('محصول اصلاح شد');
        foreach (['• جنس: برزنت', '• توضیح: کیف روزمره سبک', 'بدون قیمت', '• رنگ: مشکی، قهوه‌ای'] as $line) {
            $this->assertStringContainsString($line, $revised->text);
        }
        // Corrected values are listed apart from the ones still to confirm.
        $this->assertStringContainsString("تأییدشده:\n• توضیح: کیف روزمره سبک\n• جنس: برزنت", $revised->text);
        $this->assertStringNotContainsString('• جنس: برزنت', explode('تأییدشده:', $revised->text)[0]);
        $id = $product->public_id;
        $this->assertSame([["product:confirm:{$id}:2"], ["product:reject:{$id}:2"]], array_map(fn (array $row): array => array_column($row, 'callback_data'), $revised->buttons));

        $this->press("product:confirm:{$id}:2", 3101);
        $this->assertSame([], app(ProductDraftNormalizer::class)->fieldsRequiringConfirmation($this->payload($product, 3)));
        $approvable = $this->sent('مقدارهای پیشنهادی تأیید شد');
        // Gate A sees every value it approves, the corrected ones included.
        $this->assertStringContainsString('• جنس: برزنت', $approvable->text);
        $this->assertStringContainsString('• کد: BAG-1', $approvable->text);
        $this->press("product:approve:{$id}:3", 3101);
        $this->assertSame(3, $product->fresh()->approved_revision);
    }

    public function test_values_are_removed_replaced_and_added_by_label(): void
    {
        [, $photo] = $this->extracted(3102, [$this->candidate()]);
        $product = Product::query()->sole();
        $this->text('/done', 3102);

        $this->replyTo($this->sent('در انتظار تأیید'), "کد: حذف\nدسته: کیف › روزمره\nاندازه: بزرگ، متوسط\nرنگ: حذف\nنام: کیف برزنتی", 3102);

        $after = $this->payload($product, 2);
        $this->assertSame(['value' => null, 'status' => 'unknown'], $after['sku']);
        $this->assertSame(['value' => ['کیف', 'روزمره'], 'status' => 'confirmed'], $after['category_path']);
        $this->assertSame(['value' => 'کیف برزنتی', 'status' => 'confirmed'], $after['name']);
        $this->assertNull($this->attribute($after, 'رنگ'));
        $this->assertSame(['name' => 'اندازه', 'values' => ['بزرگ', 'متوسط'], 'status' => 'confirmed'], $this->attribute($after, 'اندازه'));
        $this->assertSame('suggested', $this->attribute($after, 'جنس')['status']);
        $this->assertNull($product->fresh()->sku_key);
        // Removing رنگ moved جنس up one place; it still cites the photo it came from.
        $this->assertSame('جنس', $after['attributes'][0]['name']);
        $this->assertContains(['field' => 'attributes.0', 'source' => 'ai_extraction', 'source_asset_ids' => [$photo]], $after['evidence']);
        $this->assertStringContainsString('ویژگی تازه افزوده شد: «اندازه»', $this->lastReply());
    }

    public function test_a_wrong_correction_changes_nothing_and_says_why(): void
    {
        $this->extracted(3103, [$this->candidate()]);
        $product = Product::query()->sole();
        $this->text('/done', 3103);
        $card = $this->sent('در انتظار تأیید');

        foreach ([
            'نام: حذف' => 'نام محصول حذف نمی‌شود',
            'قیمت: 120000' => 'واحد قیمت را بنویس',
            "جنس: برزنت\nجنس: پارچه" => 'دو بار آمده است',
            'وزن: حذف' => 'ویژگی «وزن» روی این محصول نیست',
            'دسته: a › b › c › d › e › f' => 'دسته حداکثر ۵ سطح دارد',
            'کد: '.str_repeat('A', 65) => 'حداکثر ۶۴ نویسه',
            // A line without a label is the name-and-price form, so "رنگ: آبی" is read as a price.
            "جنس برزنت\nرنگ: آبی" => 'قیمت را فقط با عدد صحیح',
            'جنس:' => 'هر خط باید «نام مورد: مقدار» باشد',
            'جنس: ،' => 'مقدار «جنس» خالی است',
            'دسته: ›' => 'دسته خالی است',
            'توضیح: '.str_repeat('م', 1001) => 'توضیح از ربات حداکثر 1000 نویسه است',
        ] as $text => $answer) {
            $this->replyTo($card, $text, 3103);
            $this->assertStringContainsString($answer, $this->lastReply(), $text);
        }
        $this->assertSame(1, $product->fresh()->current_revision);
    }

    public function test_a_price_that_normalizes_to_nothing_is_a_format_reply_not_an_error(): void
    {
        // ZWJ survives line() but not the price reader, so the parser must not hand on an empty price.
        $this->assertStringContainsString('قیمت را با عدد و واحد بنویس', app(TelegramProductCorrection::class)->parse("قیمت: \u{200D}"));
    }

    public function test_only_the_card_revision_and_its_owner_are_corrected(): void
    {
        $this->extracted(3104, [$this->candidate()]);
        $product = Product::query()->sole();
        $this->text('/done', 3104);
        $card = $this->sent('در انتظار تأیید');

        $this->replyTo($card, 'جنس: برزنت', 3104);
        $this->assertSame(2, $product->fresh()->current_revision);
        // The old card names revision 1: it never overwrites revision 2.
        $this->replyTo($card, 'جنس: پارچه', 3104);
        $this->assertStringContainsString('به کارت نسخه تازه پاسخ بده', $this->lastReply());

        // Another seller replying to this card reaches nothing.
        $this->seller(3105);
        $this->replyTo($this->sent('محصول اصلاح شد'), 'جنس: نایلون', 3105);
        $this->assertStringContainsString('محصول یافت نشد', $this->lastReply());
        $this->assertSame(['برزنت'], $this->attribute($this->payload($product, 2), 'جنس')['values']);
        $this->assertSame(2, $product->fresh()->current_revision);
    }

    public function test_phone_colons_the_old_two_line_form_and_ambiguous_labels(): void
    {
        $this->extracted(3107, [$this->candidate(attributes: [['name' => 'کد', 'values' => ['A1']], ['name' => 'Color', 'values' => ['red']], ['name' => 'color', 'values' => ['blue']]])]);
        $product = Product::query()->sole();
        $this->text('/done', 3107);
        $card = $this->sent('در انتظار تأیید');

        // A label naming both a field and a stored attribute, or two attributes, is refused.
        $this->replyTo($card, 'کد: B2', 3107);
        $this->assertStringContainsString('ویژگی‌ای هم‌نام «کد» دارد', $this->lastReply());
        $this->replyTo($card, 'color: green', 3107);
        $this->assertStringContainsString('چند ویژگی با نام «color» دارد', $this->lastReply());
        $this->assertSame(1, $product->fresh()->current_revision);

        // A full-width colon from a phone keyboard is a label too.
        $this->replyTo($card, 'جنس：برزنت', 3107);
        $this->assertSame('کیف چرمی', $this->payload($product, 2)['name']['value']);
        $this->assertSame(['name' => 'جنس', 'values' => ['برزنت'], 'status' => 'confirmed'], $this->attribute($this->payload($product, 2), 'جنس'));

        // A name with a colon over a price line is still the ADR-029 form.
        $this->replyTo($this->sent('محصول اصلاح شد'), "ست: سیاه\n۱۵۰٬۰۰۰ تومان", 3107);
        $after = $this->payload($product, 3);
        $this->assertSame('ست: سیاه', $after['name']['value']);
        $this->assertSame(1_500_000, $after['pricing']['regular_price_irr']);
    }

    public function test_a_code_another_product_holds_is_refused(): void
    {
        // Without suggested prices /done shows the cards, not the grouped price question.
        $this->extracted(3106, [$this->candidate('کیف اول', 'BAG-1', null), $this->candidate('کیف دوم', 'BAG-2', null)]);
        $this->text('/done', 3106);
        $first = Product::query()->orderBy('id')->firstOrFail();
        $this->press("product:confirm:{$first->public_id}:1", 3106);

        $this->replyTo($this->sent('«کیف دوم»'), 'کد: bag-1', 3106);

        $this->assertStringContainsString('برای محصول دیگری ثبت شده است', $this->lastReply());
        $this->assertSame(1, Product::query()->orderByDesc('id')->firstOrFail()->current_revision);
    }
}
