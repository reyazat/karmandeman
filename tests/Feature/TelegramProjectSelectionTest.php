<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\SelectTelegramProjectAction;
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

/** The bot's current batch and the reject button (ADR-028). */
class TelegramProjectSelectionTest extends TestCase
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

    /** @return list<string> callback data of one button column in the newest reply containing $fragment */
    private function buttonsOf(string $fragment, int $column = 0): array
    {
        $job = Queue::pushed(SendTelegramMessage::class)->filter(fn (SendTelegramMessage $job) => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, $fragment);

        return array_map(fn (array $row) => $row[$column]['callback_data'], $job->buttons);
    }

    public function test_projects_lists_open_batches_with_select_buttons_and_photos_follow_the_choice(): void
    {
        $this->seller(1201);
        $this->text('/new Second', 1201)->assertOk();
        [$first, $second] = Project::query()->orderBy('id')->get()->all();

        $this->text('/projects', 1201)->assertOk();
        $this->replied('Second — collecting_input (جاری)');
        $this->assertSame(["project:select:{$first->public_id}"], $this->buttonsOf('آخرین پروژه‌ها'));

        $this->press("project:select:{$first->public_id}", 1201)->assertOk();
        $this->replied('پروژه «Batch» انتخاب شد');
        $this->text("/product کیف\n۱۰۰ تومان", 1201)->assertOk();
        $this->assertSame($first->id, Product::query()->sole()->project_id);
        // Selecting changes no product data, so the batch version does not move for it.
        $this->assertSame(2, $first->fresh()->version);
    }

    public function test_closed_foreign_and_unknown_batches_cannot_be_selected(): void
    {
        $this->seller(1202);
        $project = Project::query()->sole();
        $this->seller(1203);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press("project:select:{$project->public_id}", 1203)->assertOk();
        $this->press('project:select:00000000-0000-4000-8000-000000000000', 1203)->assertOk();
        $this->assertSame(['پروژه یافت نشد.', 'پروژه یافت نشد.'], Queue::pushed(SendTelegramMessage::class)->map(fn ($job) => $job->text)->all());

        Project::query()->whereKey($project->id)->update(['state' => 'cancelled']);
        $selectedAt = DB::table('projects')->where('id', $project->id)->value('bot_selected_at');
        $this->press("project:select:{$project->public_id}", 1202)->assertOk();
        $this->replied('باز نیست');
        $this->assertSame($selectedAt, DB::table('projects')->where('id', $project->id)->value('bot_selected_at'));
    }

    public function test_reject_button_archives_the_shown_revision_only(): void
    {
        $owner = $this->seller(1204);
        $this->productButton(1204, 'کیف');
        $product = Product::query()->sole();
        $reject = $this->buttonsOf('محصول «کیف»', 1);
        $this->assertSame(["product:reject:{$product->public_id}:1"], $reject);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'کیف نو', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-before-reject'])->assertOk();
        $this->press($reject[0], 1204)->assertOk();
        $this->replied('تغییر کرده است');
        $this->assertNotSame('archived', $product->fresh()->status->value);

        $this->press("product:reject:{$product->public_id}:2", 1204)->assertOk();
        $this->replied('محصول «کیف نو» رد شد');
        $this->assertSame('archived', $product->fresh()->status->value);

        // Another seller cannot reject it, with the same answer as for an unknown product.
        $this->seller(1205);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
        $this->press("product:reject:{$product->public_id}:2", 1205)->assertOk();
        $this->press('product:reject:00000000-0000-4000-8000-000000000000:1', 1205)->assertOk();
        $this->assertSame(['محصول یافت نشد.', 'محصول یافت نشد.'], Queue::pushed(SendTelegramMessage::class)->map(fn ($job) => $job->text)->all());
    }

    public function test_a_closed_current_batch_stops_input_instead_of_falling_back(): void
    {
        $owner = $this->seller(1206);
        $this->text('/new Second', 1206)->assertOk();
        [$first, $second] = Project::query()->orderBy('id')->get()->all();
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/projects/{$second->public_id}/cancel", ['version' => 1], ['Idempotency-Key' => 'cancel-current'])->assertOk();

        $this->text("/product کیف\n۱۰۰ تومان", 1206)->assertOk();
        $this->replied('پروژه جاری «Second» دیگر ورودی نمی‌پذیرد');
        $this->text('/done', 1206)->assertOk();
        $this->replied('پروژه جاری «Second» بسته است');
        $this->assertSame(0, Product::query()->count());

        // /projects offers the other open batch, beyond the five newest if needed.
        $this->text('/projects', 1206)->assertOk();
        $this->assertSame(["project:select:{$first->public_id}"], $this->buttonsOf('آخرین پروژه‌ها'));
    }

    public function test_reject_never_reaches_a_finished_batch_or_a_stale_archived_revision(): void
    {
        $owner = $this->seller(1207);
        $this->productButton(1207, 'کیف');
        $product = Product::query()->sole();
        [$approve, $reject] = [$this->buttonsOf('محصول «کیف»')[0], $this->buttonsOf('محصول «کیف»', 1)[0]];
        $this->press($approve, 1207)->assertOk();
        $this->text('/done', 1207)->assertOk();
        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1207)->assertOk();
        $this->assertSame('product_approved', $project->fresh()->state->value);

        $this->press($reject, 1207)->assertOk();
        $this->replied('دیگر در حال بررسی نیست');
        $this->assertSame('approved', $product->fresh()->status->value);

        // An archived product with a newer revision: an old reject card reports the change.
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/projects/{$project->public_id}/reopen", ['version' => $project->fresh()->version], ['Idempotency-Key' => 'reopen-batch'])->assertOk();
        $this->patchJson("/api/v1/products/{$product->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'کیف نو', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-after-reopen'])->assertOk();
        $this->postJson("/api/v1/products/{$product->public_id}/archive", [], ['Idempotency-Key' => 'archive-by-api'])->assertOk();
        $this->postJson("/api/v1/products/{$product->public_id}/archive", [], ['Idempotency-Key' => 'archive-by-api'])->assertOk();
        $this->press($reject, 1207)->assertOk();
        $this->replied('تغییر کرده است');
    }

    public function test_a_batch_created_through_the_api_becomes_the_current_one(): void
    {
        $owner = $this->seller(1208);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/projects', ['name' => 'From API'], ['Idempotency-Key' => 'api-current'])->assertCreated();
        $api = Project::query()->orderByDesc('id')->first();

        $this->text("/product کیف\n۱۰۰ تومان", 1208)->assertOk();
        $this->replied('در پروژه «From API» ثبت شد');
        $this->assertSame($api->id, Product::query()->sole()->project_id);
    }

    public function test_selection_waits_for_and_is_refused_after_a_deletion_request(): void
    {
        $user = $this->seller(1209);
        $this->text('/new Second', 1209)->assertOk();
        $first = Project::query()->orderBy('id')->first();
        $before = DB::table('projects')->where('id', $first->id)->value('bot_selected_at');
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);

        // The race after the webhook's containment check: answered, never thrown.
        $reply = DB::transaction(fn () => app(SelectTelegramProjectAction::class)->execute($workspace, $user, $first->public_id));
        $this->assertSame('حساب در حال حذف است و تغییر تازه‌ای پذیرفته نمی‌شود.', $reply);
        $this->assertSame($before, DB::table('projects')->where('id', $first->id)->value('bot_selected_at'));
    }
}
