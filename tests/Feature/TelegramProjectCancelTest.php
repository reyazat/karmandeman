<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\CancelTelegramProjectAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Cancelling the bot's current batch with /cancel and a confirm button (ADR-031, FR-063). */
class TelegramProjectCancelTest extends TestCase
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

    private function press(string $data, int $telegramId, ?string $callbackId = null): TestResponse
    {
        return $this->update(['callback_query' => [
            'id' => $callbackId ?? 'cb-'.++$this->updates, 'data' => $data,
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

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    /** @return list<string> callback data of one button column in the newest reply containing $fragment */
    private function buttonsOf(string $fragment, int $column = 0): array
    {
        $job = Queue::pushed(SendTelegramMessage::class)->filter(fn (SendTelegramMessage $job) => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, $fragment);

        return array_map(fn (array $row) => $row[$column]['callback_data'], $job->buttons);
    }

    public function test_cancel_asks_first_then_cancels_the_version_shown(): void
    {
        $this->seller(1301);
        $this->text("/product کیف\n۱۰۰ تومان", 1301)->assertOk();
        $project = Project::query()->sole();

        $this->text('/cancel', 1301)->assertOk();
        $this->replied('پروژه «Batch» با 1 محصول فعال لغو شود؟');
        // Asking writes nothing.
        $this->assertSame('collecting_input', $project->fresh()->state->value);
        $this->assertSame(["project:cancel:{$project->public_id}:{$project->fresh()->version}"], $this->buttonsOf('لغو شود؟'));

        $this->press($this->buttonsOf('لغو شود؟')[0], 1301, 'cb-cancel')->assertOk();
        $this->replied('پروژه «Batch» لغو شد');
        $this->assertSame('cancelled', $project->fresh()->state->value);
        // Products stay (ADR-019); the batch stays current, so input is refused, not redirected.
        $this->assertSame(1, Product::query()->count());
        $this->text("/product کفش\n۲۰۰ تومان", 1301)->assertOk();
        $this->replied('دیگر ورودی نمی‌پذیرد');
        $this->assertSame(1, Product::query()->count());

        // The same button press again replays the answer instead of failing.
        $version = $project->fresh()->version;
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press("project:cancel:{$project->public_id}:".($version - 1), 1301, 'cb-cancel')->assertOk();
        $this->assertSame($version, $project->fresh()->version);
        $this->assertSame(['پروژه «Batch» لغو شد. با /projects پروژه دیگری انتخاب کن یا با /new پروژه تازه بساز.'],
            Queue::pushed(SendTelegramMessage::class)->map(fn ($job) => $job->text)->all());
        $this->text('/cancel', 1301)->assertOk();
        $this->replied('پروژه جاری «Batch» قابل لغو نیست');
    }

    public function test_a_stale_offer_does_not_cancel_a_changed_batch(): void
    {
        $this->seller(1302);
        $project = Project::query()->sole();
        $this->text('/cancel', 1302)->assertOk();
        $button = $this->buttonsOf('لغو شود؟')[0];

        $this->text("/product کیف\n۱۰۰ تومان", 1302)->assertOk();
        $this->press($button, 1302)->assertOk();
        $this->replied('پروژه پس از این پیام تغییر کرده است و لغو نشد');
        $this->assertSame('collecting_input', $project->fresh()->state->value);
    }

    public function test_an_approved_batch_offer_warns_that_its_final_version_stops_counting(): void
    {
        $owner = $this->seller(1303);
        $project = Project::query()->sole();
        Project::query()->whereKey($project->id)->update(['state' => 'product_approved']);

        $this->text('/cancel', 1303)->assertOk();
        $this->replied('نسخه نهایی این پروژه دیگر خروجی معتبر نیست');
        $this->press($this->buttonsOf('لغو شود؟')[0], 1303)->assertOk();
        $this->assertSame('cancelled', $project->fresh()->state->value);
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertStatus(409);
    }

    public function test_foreign_unknown_and_closed_batches_are_refused(): void
    {
        $this->seller(1304);
        $project = Project::query()->sole();
        $this->seller(1305);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press("project:cancel:{$project->public_id}:1", 1305)->assertOk();
        $this->press('project:cancel:00000000-0000-4000-8000-000000000000:1', 1305)->assertOk();
        $this->assertSame(['پروژه یافت نشد.', 'پروژه یافت نشد.'], Queue::pushed(SendTelegramMessage::class)->map(fn ($job) => $job->text)->all());
        $this->assertSame('collecting_input', $project->fresh()->state->value);

        Project::query()->whereKey($project->id)->update(['state' => 'completed']);
        $this->press("project:cancel:{$project->public_id}:{$project->version}", 1304)->assertOk();
        $this->replied('این پروژه دیگر قابل لغو نیست.');
        $this->assertSame('completed', $project->fresh()->state->value);
    }

    public function test_a_batch_in_review_can_be_cancelled(): void
    {
        $this->seller(1307);
        $this->text("/product کیف\n۱۰۰ تومان", 1307)->assertOk();
        $this->text('/done', 1307)->assertOk();
        $project = Project::query()->sole();
        $this->assertSame('review_required', $project->fresh()->state->value);

        $this->text('/cancel', 1307)->assertOk();
        $this->press($this->buttonsOf('لغو شود؟')[0], 1307)->assertOk();
        $this->assertSame('cancelled', $project->fresh()->state->value);
    }

    public function test_a_deletion_request_after_the_offer_is_answered_without_cancelling(): void
    {
        $user = $this->seller(1308);
        $project = Project::query()->sole();
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);

        // The race after the webhook's containment check: the workspace lock refuses it.
        $reply = DB::transaction(fn () => app(CancelTelegramProjectAction::class)
            ->execute($workspace, $user, 'cb-race', $project->public_id, $project->version));
        $this->assertSame('حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.', $reply);
        $this->assertSame('collecting_input', $project->fresh()->state->value);

        // Through the webhook the containment check answers first.
        $this->press("project:cancel:{$project->public_id}:{$project->version}", 1308)->assertOk();
        $this->assertSame('collecting_input', $project->fresh()->state->value);
    }

    public function test_cancel_without_a_batch_explains_what_to_do(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(1306));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');

        $this->text('/cancel', 1306)->assertOk();
        $this->replied('پروژه جاری نداری');
    }
}
