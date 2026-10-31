<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesExtractedBatches;
use Tests\TestCase;

/** One grouped question for the suggested prices of a batch, answered for exactly the rows shown (ADR-035). */
class TelegramPriceQuestionTest extends TestCase
{
    use DrivesExtractedBatches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExtractedBatches();
    }

    /** A batch of a bag and a shoe with suggested prices and a hat without one. */
    private function batch(int $telegramId): void
    {
        $this->extracted($telegramId, [
            $this->candidate('کیف'),
            $this->candidate('کفش', ['amount' => 80000, 'unit' => 'IRT']),
            $this->candidate('کلاه', null),
        ]);
    }

    private function question(): string
    {
        $project = Project::query()->latest('id')->firstOrFail();

        return "{$project->public_id}:{$project->version}";
    }

    /** @return list<Product> */
    private function products(): array
    {
        return Product::query()->orderBy('id')->get()->all();
    }

    public function test_suggested_prices_are_asked_once_and_confirmed_together(): void
    {
        $this->batch(3101);
        [$bag, $shoe, $hat] = $this->products();

        $this->text('/done', 3101);
        $summary = $this->sent('هنوز تأیید نشده‌اند');
        $this->assertStringContainsString('3 از 3 محصول هنوز تأیید نشده‌اند (3 مقدار پیشنهادی یا نام خالی دارند، 1 بدون قیمت‌اند)', $summary->text);
        $question = $this->sent('قیمت این 2 محصول از عکس‌ها پیشنهاد شده است');
        $this->assertStringContainsString("1. قیمت 120,000 تومان — کیف\n2. قیمت 80,000 تومان — کفش", $question->text);
        $this->assertStringNotContainsString('کلاه', $question->text);
        $id = $this->question();
        $this->assertSame(["project:prices:{$id}", "project:noprices:{$id}", "project:cards:{$id}"], $this->buttons($question));
        // One decision per message: no product card until the question is answered.
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'در انتظار تأیید'));

        $this->press("project:prices:{$id}", 3101);

        foreach ([$bag, $shoe] as $product) {
            $after = $this->payload($product, 2);
            $this->assertSame('confirmed', $after['pricing']['status']);
            $this->assertSame('ai_extraction', $after['pricing']['source']);
            // Only the price changes; the rest still waits on the product's own card.
            $this->assertSame('suggested', $after['name']['status']);
            $this->assertSame($this->payload($product, 1)['evidence'], $after['evidence']);
        }
        $this->assertSame(1, $hat->fresh()->current_revision);
        $this->sent('قیمت‌های پیشنهادی فهرست تأیید شدند');
        // The answer brings the cards, now without a "without price" button for confirmed prices.
        $card = $this->sent('در انتظار تأیید: «کیف»');
        $this->assertSame(["product:confirm:{$bag->public_id}:2", "product:reject:{$bag->public_id}:2"], $this->buttons($card));

        // A second press meets the newer batch version and changes nothing.
        $this->press("project:prices:{$id}", 3101);
        $this->sent('پروژه پس از این پیام تغییر کرده است و قیمتی تغییر نکرد');
        $this->assertSame(2, $bag->fresh()->current_revision);
    }

    public function test_without_price_for_now_drops_every_listed_price(): void
    {
        $this->batch(3102);
        [$bag, $shoe, $hat] = $this->products();
        $this->text('/done', 3102);

        $this->press('project:noprices:'.$this->question(), 3102);

        foreach ([$bag, $shoe] as $product) {
            $this->assertSame(['minor_currency' => 'IRR', 'regular_price_irr' => null, 'display_unit' => null, 'source' => null, 'status' => 'unknown'], $this->payload($product, 2)['pricing']);
            $this->assertTrue($product->fresh()->price_missing);
        }
        $this->assertSame(1, $hat->fresh()->current_revision);
        $this->sent('محصولات فهرست فعلاً بدون قیمت ماندند');
    }

    public function test_stale_foreign_and_untrusted_answers_change_nothing(): void
    {
        $this->batch(3103);
        [$bag, $shoe] = $this->products();
        $this->text('/done', 3103);
        $id = $this->question();

        // Another seller pressing a forged answer for this batch.
        $this->seller(3104);
        $this->press("project:prices:{$id}", 3104);
        $this->sent('پروژه یافت نشد');
        $this->press("project:cards:{$id}", 3104);
        $this->assertSame([1, 1], [$bag->fresh()->current_revision, $shoe->fresh()->current_revision]);

        // One untrusted row stops the whole answer: no price of the list changes.
        ProductRevision::query()->where('product_id', $shoe->id)->update(['payload_mac' => str_repeat('0', 64)]);
        $this->press("project:prices:{$id}", 3103);
        $this->sent('نسخه یکی از این محصولات قابل اعتماد نیست و قیمتی تغییر نکرد');
        $this->assertSame([1, 1], [$bag->fresh()->current_revision, $shoe->fresh()->current_revision]);
        $this->assertSame(1, ProductRevision::query()->where('product_id', $bag->id)->count());
    }

    public function test_a_changed_batch_refuses_the_old_question_and_cards_are_one_press_away(): void
    {
        $this->batch(3105);
        [$bag, $shoe] = $this->products();
        $this->text('/done', 3105);
        $id = $this->question();

        // Review one at a time instead.
        $this->press("project:cards:{$id}", 3105);
        $this->sent('در انتظار تأیید: «کفش»');

        // A product changed after the question was sent: the old answer acts on nothing.
        $this->press("product:confirm:{$bag->public_id}:1", 3105);
        $this->press("project:noprices:{$id}", 3105);
        $this->sent('پروژه پس از این پیام تغییر کرده است و قیمتی تغییر نکرد');
        $this->assertSame(1, $shoe->fresh()->current_revision);
        $this->assertSame('confirmed', $this->payload($bag, 2)['pricing']['status']);

        // One suggested price left is no group: /done shows the cards directly.
        Queue::fake([SendTelegramMessage::class]);
        $this->text('/done', 3105);
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'از عکس‌ها پیشنهاد شده است'));
        $this->assertContains("product:noprice:{$shoe->public_id}:1", $this->buttons($this->sent('در انتظار تأیید: «کفش»')));
    }

    public function test_rows_without_a_name_or_with_the_same_label_are_never_grouped(): void
    {
        $this->extracted(3106, [
            $this->candidate(null),
            $this->candidate('کیف'),
            $this->candidate('کیف', ['amount' => 80000, 'unit' => 'IRT']),
        ]);

        $this->text('/done', 3106);
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'از عکس‌ها پیشنهاد شده است'));
        $this->sent('در انتظار تأیید: «کیف»');

        // A forged answer for the same rows is refused too.
        $this->press('project:prices:'.$this->question(), 3106);
        $this->sent('پروژه پس از این پیام تغییر کرده است و قیمتی تغییر نکرد');
        $this->assertSame([1, 1, 1], array_map(fn (Product $product): int => $product->fresh()->current_revision, $this->products()));
    }

    public function test_more_rows_than_one_question_holds_are_asked_in_rounds(): void
    {
        $candidates = [];
        for ($i = 1; $i <= 25; $i++) {
            $candidates[] = $this->candidate("محصول {$i}", ['amount' => 1000 * $i, 'unit' => 'IRT']);
        }
        $this->extracted(3107, $candidates);

        $this->text('/done', 3107);
        $first = $this->sent('قیمت این 20 محصول از عکس‌ها پیشنهاد شده است');
        $this->assertStringContainsString('20. قیمت 20,000 تومان — محصول 20', $first->text);
        $this->assertStringNotContainsString('محصول 21', $first->text);

        $this->press('project:prices:'.$this->question(), 3107);
        $products = $this->products();
        $this->assertSame(array_merge(array_fill(0, 20, 2), array_fill(0, 5, 1)), array_map(fn (Product $product): int => $product->fresh()->current_revision, $products));
        // The answer asks the next round itself.
        $second = $this->sent('قیمت این 5 محصول از عکس‌ها پیشنهاد شده است');
        $this->assertStringContainsString('1. قیمت 21,000 تومان — محصول 21', $second->text);
    }
}
