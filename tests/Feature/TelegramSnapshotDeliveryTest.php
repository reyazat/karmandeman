<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\SendTelegramSnapshotAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Jobs\Telegram\SendTelegramSnapshot;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** The approved batch as a JSON file from the bot (ADR-030). */
class TelegramSnapshotDeliveryTest extends TestCase
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
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class, SendTelegramSnapshot::class]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
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

        return $user;
    }

    /** An approved one-product batch; returns its project. */
    private function approvedBatch(int $telegramId, string $name): Project
    {
        $this->text("/new {$name}", $telegramId)->assertOk();
        $this->text("/product {$name}\n۱۲۰٬۰۰۰ تومان", $telegramId)->assertOk();
        $product = Product::query()->latest('id')->first();
        $this->press("product:approve:{$product->public_id}:1", $telegramId)->assertOk();
        $this->text('/done', $telegramId)->assertOk();
        $project = Project::query()->latest('id')->first();
        $this->press("project:approve:{$project->public_id}:{$project->version}", $telegramId)->assertOk();

        return $project->fresh();
    }

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    private function deliver(SendTelegramSnapshot $job): void
    {
        $job->handle(app(SendTelegramSnapshotAction::class));
    }

    /** @return array<string, string> multipart fields of the one sendDocument call */
    private function sentDocument(): array
    {
        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendDocument'));
        $this->assertCount(1, $sent);
        $fields = [];
        foreach ($sent[0][0]->data() as $part) {
            $fields[$part['name']] = (string) $part['contents'];
        }

        return $fields;
    }

    public function test_the_json_button_sends_the_verified_batch_as_a_file(): void
    {
        $owner = $this->seller(1401);
        $project = $this->approvedBatch(1401, 'کیف');
        $button = "project:json:{$project->public_id}:1";
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, 'پروژه تأیید شد')
            && $job->buttons === [[['text' => 'دریافت فایل JSON', 'callback_data' => $button]]]);

        $this->press($button, 1401)->assertOk();
        $this->replied('فایل JSON نسخه نهایی شماره 1 پروژه «کیف» در حال آماده‌سازی است');
        $job = Queue::pushed(SendTelegramSnapshot::class)->sole();
        // Ids only: no chat ID or product text waits in the queue.
        $this->assertSame([$owner->id, $project->public_id, 1], [$job->userId, $job->projectPublicId, $job->snapshotNumber]);
        $this->assertStringNotContainsString('1401', serialize($job));
        $this->assertStringNotContainsString('کیف', serialize($job));

        $this->deliver($job);
        $document = $this->sentDocument();
        $this->assertSame('1401', $document['chat_id']);
        $this->assertStringContainsString('نسخه نهایی شماره 1 پروژه «کیف»: 1 محصول', $document['caption']);
        Sanctum::actingAs($owner);
        $api = $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertOk()->json('data');
        $this->assertSame($api, json_decode($document['document'], true));
        $this->assertSame(1_200_000, $api['products'][0]['pricing']['regular_price_irr']);
    }

    public function test_a_stale_foreign_or_unapproved_button_queues_nothing(): void
    {
        $this->seller(1402);
        $project = $this->approvedBatch(1402, 'شال');
        $this->seller(1403);

        $this->press("project:json:{$project->public_id}:1", 1403)->assertOk();
        $this->replied('پروژه یافت نشد.');
        $this->press('project:json:00000000-0000-4000-8000-000000000000:1', 1402)->assertOk();
        $this->press("project:json:{$project->public_id}:2", 1402)->assertOk();
        $this->replied('نسخه نهایی تازه‌تری ثبت شده است');

        $this->press("project:reopen:{$project->public_id}:{$project->version}", 1402)->assertOk();
        $this->press("project:json:{$project->public_id}:1", 1402)->assertOk();
        $this->replied('نسخه نهایی معتبر ندارد');
        Queue::assertNotPushed(SendTelegramSnapshot::class);
    }

    public function test_the_worker_rechecks_before_sending(): void
    {
        $owner = $this->seller(1404);
        $project = $this->approvedBatch(1404, 'کفش');

        // Reopened after the press: the batch under correction is never sent.
        $this->press("project:reopen:{$project->public_id}:{$project->version}", 1404)->assertOk();
        $this->deliver(new SendTelegramSnapshot($owner->id, $project->public_id, 1));
        $this->replied('دیگر نسخه نهایی معتبر ندارد');

        // Approved again: the old button names snapshot 1, the batch is snapshot 2.
        $this->text('/done', 1404)->assertOk();
        $project->refresh();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 1404)->assertOk();
        $this->deliver(new SendTelegramSnapshot($owner->id, $project->public_id, 1));
        $this->replied('دکمه پیام تأیید تازه را بزن');

        // A tampered item list is refused, not sent.
        $items = DB::table('project_snapshots')->where('number', 2)->value('items');
        DB::table('project_snapshots')->where('number', 2)->update(['items' => str_replace('"revision":1', '"revision":9', $items)]);
        $this->deliver(new SendTelegramSnapshot($owner->id, $project->public_id, 2));
        $this->replied('قابل اعتماد نیست');
        DB::table('project_snapshots')->where('number', 2)->update(['items' => $items]);

        // Erasure requested after the press: nothing goes out.
        $workspaces = app(WorkspaceRepositoryInterface::class);
        $workspaces->markDeletionPending($workspaces->currentFor($owner));
        $this->deliver(new SendTelegramSnapshot($owner->id, $project->public_id, 2));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/sendDocument'));
    }
}
