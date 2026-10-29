<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Correcting a product by replying to its card, and reopening an approved batch for it (ADR-029). */
class TelegramProductEditTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const BOT_ID = 123;

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => self::BOT_ID.':test-token',
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updates] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function text(string $text, int $telegramId, ?array $repliedTo = null, ?int $messageId = null): TestResponse
    {
        $message = [
            'message_id' => $messageId ?? ++$this->updates, 'date' => time(), 'text' => $text,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ];
        if ($repliedTo !== null) {
            $message['reply_to_message'] = $repliedTo;
        }

        return $this->update(['message' => $message]);
    }

    private function press(string $data, int $telegramId): TestResponse
    {
        return $this->update(['callback_query' => [
            'id' => 'cb-'.++$this->updates, 'data' => $data,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
        ]]);
    }

    private function seller(int $telegramId): User
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');
        $this->text('/new Batch', $telegramId)->assertOk();

        return $user;
    }

    /** The newest bot message containing $fragment, as Telegram puts it under reply_to_message. */
    private function card(string $fragment, int $telegramId): array
    {
        $job = Queue::pushed(SendTelegramMessage::class)->filter(fn (SendTelegramMessage $job) => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, $fragment);

        return [
            'message_id' => 900, 'date' => time(), 'text' => $job->text,
            'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Karmandeman'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
            'reply_markup' => ['inline_keyboard' => $job->buttons],
        ];
    }

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    /** @return list<string> */
    private function buttonsOf(string $fragment): array
    {
        $card = $this->card($fragment, 0);

        return array_merge(...array_map(fn (array $row) => array_column($row, 'callback_data'), $card['reply_markup']['inline_keyboard']));
    }

    private function payload(Product $product, int $revision): array
    {
        $this->assertSame($revision, $product->fresh()->current_revision);

        return ProductRevision::query()->where('product_id', $product->id)->where('revision', $revision)->sole()->payload;
    }

    public function test_a_reply_to_the_card_corrects_exactly_that_revision(): void
    {
        $this->seller(1301);
        $this->text("/product کیف\n۱۰۰٬۰۰۰ تومان", 1301)->assertOk();
        $product = Product::query()->sole();
        $first = $this->card('«کیف» با 0 عکس', 1301);
        $this->assertStringContainsString('به همین پیام پاسخ', $first['text']);

        // Name only: the price stays.
        $this->text('کیف چرم', 1301, $first)->assertOk();
        $this->replied('محصول اصلاح شد: «کیف چرم» با 0 عکس و قیمت 100,000 تومان (نسخه 2)');
        $this->assertSame(["product:approve:{$product->public_id}:2", "product:reject:{$product->public_id}:2"], $this->buttonsOf('«کیف چرم»'));
        $payload = $this->payload($product, 2);
        $this->assertSame(1_000_000, $payload['pricing']['regular_price_irr']);
        $this->assertSame('toman', $payload['pricing']['display_unit']);

        // The first card is stale now: neither a reply nor its approve button reaches revision 2.
        $this->text("کیف کهنه\n۵ ریال", 1301, $first)->assertOk();
        $this->replied('این محصول پس از این پیام تغییر کرده است؛ به کارت نسخه تازه پاسخ بده.');
        $this->press("product:approve:{$product->public_id}:1", 1301)->assertOk();
        $this->assertNull($product->fresh()->approved_revision);

        // A new price with its unit replaces the old one.
        $this->text("کیف چرم\n۹۰۰٬۰۰۰ ریال", 1301, $this->card('«کیف چرم» با 0 عکس و قیمت 100,000', 1301))->assertOk();
        $payload = $this->payload($product, 3);
        $this->assertSame(900_000, $payload['pricing']['regular_price_irr']);
        $this->assertSame('rial', $payload['pricing']['display_unit']);
        $this->assertSame(3, DB::table('product_revisions')->where('product_id', $product->id)->count());
    }

    public function test_a_retried_reply_makes_one_revision_and_a_bad_reply_none(): void
    {
        $this->seller(1302);
        $this->text("/product شال\n۵۰٬۰۰۰ تومان", 1302)->assertOk();
        $product = Product::query()->sole();
        $card = $this->card('«شال»', 1302);

        // Telegram redelivers the same message under a new update_id.
        $this->text('شال ابریشم', 1302, $card, messageId: 7001)->assertOk();
        $this->text('شال ابریشم', 1302, $card, messageId: 7001)->assertOk();
        $this->assertSame(2, $product->fresh()->current_revision);

        $card = $this->card('«شال ابریشم»', 1302);
        $this->text("شال\n۵۰۰۰۰", 1302, $card)->assertOk();
        $this->replied('واحد قیمت را بنویس');
        $this->text("شال\n۱۲.۵ تومان", 1302, $card)->assertOk();
        $this->replied('قیمت را فقط با عدد صحیح');
        $this->text("یک\nدو\nسه", 1302, $card)->assertOk();
        $this->replied('برای اصلاح، نام محصول را در خط اول');
        $this->assertSame(2, $product->fresh()->current_revision);
    }

    public function test_only_this_bots_own_card_of_this_seller_is_corrected(): void
    {
        $this->seller(1303);
        $this->text("/product کفش\n۲۰۰٬۰۰۰ تومان", 1303)->assertOk();
        $product = Product::query()->sole();
        $card = $this->card('«کفش»', 1303);
        $this->seller(1304);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);

        // Another seller replying to a forwarded card reaches nothing.
        $this->text('کفش دزدی', 1304, $card)->assertOk();
        $this->replied('محصول یافت نشد.');

        // A message that only looks like a card is ordinary text.
        $this->text('کفش جعلی', 1303, ['from' => ['id' => 999, 'is_bot' => true]] + $card)->assertOk();
        $this->text('کفش جعلی', 1303, ['from' => ['id' => self::BOT_ID, 'is_bot' => false]] + $card)->assertOk();
        $this->text('کفش جعلی', 1303, ['reply_markup' => ['inline_keyboard' => [[['text' => 'x', 'callback_data' => 'project:select:'.$product->public_id]]]]] + $card)->assertOk();
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, 'اصلاح شد'));

        // A command is a command even as a reply.
        $this->text('/help', 1303, $card)->assertOk();
        $this->replied('دستورهای موجود');
        $this->assertSame(1, $product->fresh()->current_revision);
    }

    public function test_any_reply_shape_is_answered_never_retried_and_a_tampered_revision_is_not_reused(): void
    {
        $this->seller(1307);
        $this->text("/product کلاه\n۳۰٬۰۰۰ تومان", 1307)->assertOk();
        $product = Product::query()->sole();
        $card = $this->card('«کلاه»', 1307);

        // A large inline keyboard or an odd button is a reply to something else, not a 422 Telegram retries.
        $rows = array_fill(0, 25, [['text' => 'x', 'url' => 'https://example.com']]);
        $this->text('سلام', 1307, ['reply_markup' => ['inline_keyboard' => $rows]] + $card)->assertOk()->assertJsonPath('data.status', 'ok');
        $this->text('سلام', 1307, ['reply_markup' => ['inline_keyboard' => [[['text' => 'x', 'callback_data' => ['a']]], 'row']]] + $card)
            ->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertSame(1, $product->fresh()->current_revision);

        // Content swapped in the database fails its own MAC; a correction never re-signs it.
        DB::table('product_revisions')->where('product_id', $product->id)->update(['payload_mac' => str_repeat('0', 64)]);
        $this->text('کلاه پشمی', 1307, $card)->assertOk();
        $this->replied('قابل اعتماد نیست');
        $this->assertSame(1, $product->fresh()->current_revision);
    }

    public function test_a_batch_past_approval_gets_no_dead_reopen_button(): void
    {
        $this->seller(1308);
        $this->text("/product کمربند\n۸۰٬۰۰۰ تومان", 1308)->assertOk();
        $card = $this->card('«کمربند»', 1308);
        Project::query()->update(['state' => 'quoted']);

        $this->text('کمربند چرم', 1308, $card)->assertOk();
        $this->replied('از مرحله تأیید گذشته است');
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains(json_encode($job->buttons), 'project:reopen'));
    }

    public function test_an_approved_batch_is_corrected_only_after_reopen(): void
    {
        $owner = $this->seller(1305);
        $this->text("/product کیف\n۱۲۰٬۰۰۰ تومان", 1305)->assertOk();
        $product = Product::query()->sole();
        $card = $this->card('«کیف»', 1305);
        $this->press("product:approve:{$product->public_id}:1", 1305)->assertOk();
        $this->text('/done', 1305)->assertOk();
        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1305)->assertOk();
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $approvedVersion = $project->fresh()->version;

        $this->text("کیف\n۱۵۰٬۰۰۰ تومان", 1305, $card)->assertOk();
        $this->replied('تأیید شده است و محصولش بدون بازگشایی اصلاح نمی‌شود');
        $reopen = "project:reopen:{$project->public_id}:{$approvedVersion}";
        $this->assertSame([$reopen], $this->buttonsOf('بدون بازگشایی'));
        $this->assertSame(1, $product->fresh()->current_revision);

        // A stale or foreign reopen button does nothing.
        $this->press("project:reopen:{$project->public_id}:".($approvedVersion - 1), 1305)->assertOk();
        $this->replied('پروژه پس از این پیام تغییر کرده است');
        $this->seller(1306);
        $this->press($reopen, 1306)->assertOk();
        $this->replied('پروژه یافت نشد.');
        $this->assertSame('product_approved', $project->fresh()->state->value);

        $this->press($reopen, 1305)->assertOk();
        $this->assertSame('review_required', $project->fresh()->state->value);
        $this->replied('برای اصلاح باز شد و پروژه جاری ربات است');
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_APPROVED');

        // The reopen answer resends each card to reply to.
        $this->text("کیف\n۱۵۰٬۰۰۰ تومان", 1305, $this->card('محصول: «کیف»', 1305))->assertOk();
        $this->press("product:approve:{$product->public_id}:2", 1305)->assertOk();
        $this->text('/done', 1305)->assertOk();
        $project->refresh();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1305)->assertOk();
        $this->replied('در نسخه نهایی شماره 2 ثبت شد');
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertOk()
            ->assertJsonPath('data.number', 2)
            ->assertJsonPath('data.products.0.revision', 2)
            ->assertJsonPath('data.products.0.pricing.regular_price_irr', 1_500_000);
    }
}
