<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
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

/** `/done` and the batch approval button in the bot (ADR-027). */
class TelegramBatchApprovalTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updates] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function text(string $text, int $telegramId): TestResponse
    {
        return $this->update(['message' => [
            'message_id' => ++$this->updates, 'date' => time(), 'text' => $text,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ]]);
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

    /** The approve button the bot attached to its /product reply. */
    private function productButton(int $telegramId, string $name): string
    {
        $this->text("/product {$name}\n۱۰۰٬۰۰۰ تومان", $telegramId)->assertOk();
        $button = null;
        Queue::assertPushed(SendTelegramMessage::class, function (SendTelegramMessage $job) use ($name, &$button): bool {
            if (str_contains($job->text, "«{$name}»") && $job->buttons !== []) {
                $button = $job->buttons[0][0]['callback_data'];
            }

            return $button !== null;
        });

        return (string) $button;
    }

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    /** @return list<string> callback data of the buttons in the newest reply containing $fragment */
    private function buttonsOf(string $fragment): array
    {
        $job = Queue::pushed(SendTelegramMessage::class)->filter(fn (SendTelegramMessage $job) => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, $fragment);

        return array_map(fn (array $row) => $row[0]['callback_data'], $job->buttons);
    }

    public function test_done_closes_input_lists_pending_products_and_approves_the_batch(): void
    {
        $owner = $this->seller(1101);
        $first = $this->productButton(1101, 'کیف');
        $this->productButton(1101, 'کفش');
        $this->press($first, 1101)->assertOk();
        [$bag, $shoe] = Product::query()->orderBy('id')->get()->all();

        $this->text('/done', 1101)->assertOk();
        $project = Project::query()->sole();
        $this->assertSame('review_required', $project->state->value);
        $this->replied('1 از 2 محصول هنوز تأیید نشده‌اند');
        // The waiting product comes as its own card: what the button approves is shown with it.
        $this->assertSame(["product:approve:{$shoe->public_id}:1"], $this->buttonsOf('در انتظار تأیید: «کفش» با 0 عکس و قیمت 100,000 تومان'));

        // The batch cannot be approved early, even with a forged button.
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1101)->assertOk();
        $this->replied('1 محصول هنوز تأیید نشده‌اند');

        $this->press("product:approve:{$shoe->public_id}:1", 1101)->assertOk();
        $this->text('/done', 1101)->assertOk();
        $project->refresh();
        $this->assertSame(["project:approve:{$project->public_id}:{$project->version}"], $this->buttonsOf('هر 2 محصول تأیید شده‌اند'));

        $this->press("project:approve:{$project->public_id}:{$project->version}", 1101)->assertOk()->assertJsonPath('data.status', 'ok');
        $this->replied('پروژه تأیید شد: 2 محصول در نسخه نهایی شماره 1');
        $this->assertSame('product_approved', $project->fresh()->state->value);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertOk()
            ->assertJsonPath('data.products.0.product_id', $bag->public_id)
            ->assertJsonPath('data.products.1.product_id', $shoe->public_id);
    }

    public function test_stale_foreign_and_empty_batches_are_refused(): void
    {
        $owner = $this->seller(1102);
        $this->text('/done', 1102)->assertOk();
        $this->replied('هنوز محصولی ندارد');
        $this->assertSame('collecting_input', Project::query()->sole()->state->value);

        $this->press($this->productButton(1102, 'شال'), 1102)->assertOk();
        $this->text('/done', 1102)->assertOk();
        $project = Project::query()->sole();
        $button = "project:approve:{$project->public_id}:{$project->version}";

        // A product change after /done makes the shown version stale.
        $product = Product::query()->sole();
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'شال نخی', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-after-done'])->assertOk();
        $this->press($button, 1102)->assertOk();
        $this->replied('تغییر کرده است');

        // Another seller gets the same answer as for a batch that does not exist.
        $this->seller(1103);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press($button, 1103)->assertOk();
        $this->press('project:approve:00000000-0000-4000-8000-000000000000:3', 1103)->assertOk();
        $this->assertSame(['پروژه یافت نشد.', 'پروژه یافت نشد.'], Queue::pushed(SendTelegramMessage::class)->map(fn ($job) => $job->text)->all());

        $this->assertSame(0, DB::table('project_snapshots')->count());
        $this->assertSame('review_required', $project->fresh()->state->value);
    }

    public function test_done_follows_the_current_batch_and_shows_revised_content(): void
    {
        $owner = $this->seller(1104);
        $this->productButton(1104, 'الف');
        $this->text('/new Second', 1104)->assertOk();
        $this->productButton(1104, 'ب');
        [$first, $second] = Project::query()->orderBy('id')->get()->all();

        // /done closes the current batch: the one created last.
        $this->text('/done', 1104)->assertOk();
        $this->assertSame('review_required', $second->fresh()->state->value);
        $this->assertSame('collecting_input', $first->fresh()->state->value);
        // It stays current, so /product refuses instead of writing elsewhere.
        $this->text("/product ج\n۵۰ تومان", 1104)->assertOk();
        $this->replied('پروژه جاری «Second» دیگر ورودی نمی‌پذیرد');
        $this->assertSame(2, Product::query()->count());

        $this->press("project:select:{$first->public_id}", 1104)->assertOk();
        $this->text("/product ج\n۵۰ تومان", 1104)->assertOk();
        $this->text('/done', 1104)->assertOk();
        $this->assertSame('review_required', $first->fresh()->state->value);
        $this->replied('ورودی پروژه «Batch» بسته است. 2 از 2');

        // A product revised after its card was sent gets a card with the new content.
        $product = Product::query()->where('project_id', $second->id)->sole();
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'ب', 'status' => 'confirmed'],
            'pricing' => ['regular_price' => 999_999, 'input_unit' => 'IRT', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-second'])->assertOk();
        $this->press("project:select:{$second->public_id}", 1104)->assertOk();
        $this->text('/done', 1104)->assertOk();
        $this->assertSame(["product:approve:{$product->public_id}:2"], $this->buttonsOf('«ب» با 0 عکس و قیمت 999,999 تومان'));
    }

    public function test_a_batch_with_only_rejected_products_is_never_offered(): void
    {
        $owner = $this->seller(1105);
        $this->productButton(1105, 'د');
        $this->text('/done', 1105)->assertOk();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/products/'.Product::query()->value('public_id').'/archive', [], ['Idempotency-Key' => 'reject-only'])->assertOk();

        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->text('/done', 1105)->assertOk();
        $this->replied('محصول فعالی ندارد');
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->buttons !== []);

        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1105)->assertOk();
        $this->replied('محصول فعالی ندارد');
        $this->assertSame(0, DB::table('project_snapshots')->count());
    }
}
