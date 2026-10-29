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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Gate A from the bot's button, for exactly the revision it showed (ADR-026). */
class TelegramProductApprovalTest extends TestCase
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

    public function test_button_approves_the_shown_revision_once(): void
    {
        $user = $this->seller(1001);
        $button = $this->productButton(1001, 'کیف');
        $product = Product::query()->sole();
        $this->assertSame("product:approve:{$product->public_id}:1", $button);

        $this->press($button, 1001)->assertOk()->assertJsonPath('data.status', 'ok');
        $this->replied('تأیید شد (نسخه 1)');
        $this->assertSame(1, $product->fresh()->approved_revision);
        $version = Project::query()->value('version');

        // A second press approves nothing new and the batch version stays.
        $this->press($button, 1001)->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertSame($version, Project::query()->value('version'));
        Queue::assertPushed(AnswerTelegramCallback::class, 2);

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/products/{$product->public_id}/approved")->assertOk()->assertJsonPath('data.revision', 1);
    }

    public function test_stale_foreign_and_closed_buttons_approve_nothing(): void
    {
        $owner = $this->seller(1002);
        $button = $this->productButton(1002, 'کفش');
        $product = Product::query()->sole();

        // Another seller pressing a forged button for this product.
        $this->seller(1003);
        $this->press($button, 1003)->assertOk();
        $this->replied('محصول یافت نشد');

        // A newer revision makes the old button stale.
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'کفش چرم', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-before-press'])->assertOk();
        $this->press($button, 1002)->assertOk();
        $this->replied('تغییر کرده است');

        // A cancelled batch.
        $project = Project::query()->findOrFail($product->project_id);
        $this->postJson("/api/v1/projects/{$project->public_id}/cancel", ['version' => $project->version], ['Idempotency-Key' => 'cancel-before-press'])->assertOk();
        $this->press("product:approve:{$product->public_id}:2", 1002)->assertOk();
        $this->replied('لغو شده');

        // Malformed data is ignored without a reply.
        $replies = Queue::pushed(SendTelegramMessage::class)->count();
        foreach (['product:approve:not-a-uuid:1', "product:approve:{$product->public_id}:0", "product:approve:{$product->public_id}:1:x"] as $data) {
            $this->press($data, 1002)->assertOk();
        }
        $this->assertSame($replies, Queue::pushed(SendTelegramMessage::class)->count());

        $this->assertNull($product->fresh()->approved_revision);
        $this->assertSame(0, Product::query()->whereNotNull('approved_revision')->count());
    }

    public function test_an_old_press_after_approval_reports_the_change_not_approved(): void
    {
        $owner = $this->seller(1004);
        $button = $this->productButton(1004, 'کیف');
        $product = Product::query()->sole();
        $this->press($button, 1004)->assertOk();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'کیف چرم', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-after-press'])->assertOk();
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press($button, 1004)->assertOk();
        $this->replied('تغییر کرده است');
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, 'تأیید شد'));

        $this->postJson("/api/v1/products/{$product->public_id}/archive", [], ['Idempotency-Key' => 'reject-after-press'])->assertOk();
        $this->press("product:approve:{$product->public_id}:2", 1004)->assertOk();
        $this->replied('رد شده است');
        $this->assertSame(1, $product->fresh()->approved_revision);
    }

    public function test_foreign_and_unknown_products_get_one_answer_and_group_presses_none(): void
    {
        $this->seller(1005);
        $button = $this->productButton(1005, 'شال');
        $this->seller(1006);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);

        $this->press($button, 1006)->assertOk();
        $this->press('product:approve:00000000-0000-4000-8000-000000000000:1', 1006)->assertOk();
        $texts = Queue::pushed(SendTelegramMessage::class)->map(fn (SendTelegramMessage $job) => $job->text)->all();
        $this->assertSame(['محصول یافت نشد.', 'محصول یافت نشد.'], $texts);

        $this->update(['callback_query' => [
            'id' => 'cb-group', 'data' => $button, 'from' => ['id' => 1005, 'first_name' => 'U'],
            'message' => ['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'group']],
        ]])->assertOk();
        $this->assertCount(2, Queue::pushed(SendTelegramMessage::class));
        $this->assertNull(Product::query()->sole()->approved_revision);
    }
}
