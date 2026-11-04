<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Catalog\DeliverCatalogueArtifactAction;
use App\Actions\Catalog\GenerateCatalogueArtifactAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Wallet\ReconcileCreditLedgerAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Export\DeliverCatalogueArtifact;
use App\Jobs\Export\GenerateCatalogueArtifact;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Artifact;
use App\Models\Asset;
use App\Models\CreditLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Wallet;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** The catalogue PDF of an approved batch: made and paid for once per snapshot, sent again for free (ADR-052). */
final class TelegramCatalogueTest extends TestCase
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
            'karmandeman.gift_credits' => 100,
            'karmandeman.credits.catalogue_pack' => 2,
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class, GenerateCatalogueArtifact::class, DeliverCatalogueArtifact::class]);
        $this->fakeTelegram(ImageFixture::make(7, 'png', 64, 48));
    }

    private function fakeTelegram(string $photo): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'photos/p.png', 'file_size' => strlen($photo)]]),
            'api.telegram.org/file/bot*' => Http::response($photo),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updates] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function message(array $fields, int $telegramId): void
    {
        $this->update(['message' => $fields + [
            'message_id' => ++$this->updates, 'date' => time(),
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ]]);
    }

    private function press(string $data, int $telegramId): void
    {
        $this->update(['callback_query' => [
            'id' => 'cb-'.++$this->updates, 'data' => $data,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
        ]]);
    }

    private function seller(int $telegramId): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'فروشگاه نسترن'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
    }

    /** A one-product batch with a photo, approved from the bot. */
    private function approvedBatch(int $telegramId): Project
    {
        $this->seller($telegramId);
        $this->message(['text' => '/new مجموعه آبان'], $telegramId);
        $this->message(['photo' => [['file_id' => 'f', 'width' => 64, 'height' => 48, 'file_size' => 100]]], $telegramId);
        $this->message(['text' => "/product کیف روزمره\n۳۵۰٬۰۰۰ تومان"], $telegramId);
        $product = Product::query()->latest('id')->firstOrFail();
        $this->press("product:approve:{$product->public_id}:1", $telegramId);
        $this->message(['text' => '/done'], $telegramId);
        $project = Project::query()->latest('id')->firstOrFail();
        $this->press("project:approve:{$project->public_id}:{$project->version}", $telegramId);

        return $project->fresh();
    }

    private function lastReply(): string
    {
        return Queue::pushed(SendTelegramMessage::class)->last()->text;
    }

    private function wallet(): Wallet
    {
        return Wallet::query()->latest('id')->firstOrFail();
    }

    private function generate(): void
    {
        $job = Queue::pushed(GenerateCatalogueArtifact::class)->last();
        app(GenerateCatalogueArtifactAction::class)->execute($job->artifactId);
    }

    private function deliver(): void
    {
        $job = Queue::pushed(DeliverCatalogueArtifact::class)->last();
        app(DeliverCatalogueArtifactAction::class)->execute($job->artifactId, $job->userId);
    }

    /** @return list<string> every ledger mismatch the daily check would log */
    private function mismatches(): array
    {
        return array_column(app(ReconcileCreditLedgerAction::class)->execute()['mismatches'], 'check');
    }

    /** @return array<string, string> multipart fields of each sendDocument call */
    private function documents(): array
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument'))
            ->map(function (array $pair): array {
                $fields = [];
                foreach ($pair[0]->data() as $part) {
                    $fields[$part['name']] = (string) $part['contents'];
                    if ($part['name'] === 'document') {
                        $fields['filename'] = (string) $part['filename'];
                        $fields['type'] = (string) ($part['headers']['Content-Type'] ?? '');
                    }
                }

                return $fields;
            })->values()->all();
    }

    public function test_the_first_file_is_made_and_charged_once_and_sent_again_for_free(): void
    {
        $project = $this->approvedBatch(1601);
        $button = "project:pdf:{$project->public_id}:1";
        $this->assertStringContainsString('کاتالوگ PDF همین نسخه 2 اعتبار است', Queue::pushed(SendTelegramMessage::class, fn ($job) => str_contains($job->text, 'پروژه تأیید شد'))->last()->text);

        $this->press($button, 1601);
        $this->press($button, 1601);
        $this->assertStringContainsString('در حال ساخت است', $this->lastReply());
        $artifact = Artifact::query()->sole();
        $this->assertSame([Artifact::STATUS_QUEUED, 2], [$artifact->status, $artifact->price_credits]);
        Queue::assertPushed(GenerateCatalogueArtifact::class, 1);
        // Held, not spent, until the file exists.
        $this->assertSame([100, 98], [$this->wallet()->balance_cached, $this->wallet()->available()]);

        $this->generate();

        $artifact->refresh();
        $this->assertSame(Artifact::STATUS_READY, $artifact->status);
        $this->assertSame([98, 98], [$this->wallet()->fresh()->balance_cached, $this->wallet()->fresh()->available()]);
        $stored = Storage::disk('assets')->get($artifact->storage_key);
        $this->assertStringStartsNotWith('%PDF', $stored);
        $this->assertSame(1, CreditLedgerEntry::query()->where('related_type', CreditLedgerEntry::RELATED_ARTIFACT)->where('type', 'capture')->count());

        $this->deliver();
        [$document] = $this->documents();
        $this->assertStringStartsWith('%PDF-', $document['document']);
        $this->assertSame('application/pdf', $document['type']);
        $this->assertStringEndsWith('.pdf', $document['filename']);
        $this->assertSame(1, preg_match_all('#/Type /Page\b#', $document['document']));
        $this->assertStringContainsString('دریافت دوباره همین فایل رایگان است', $document['caption']);

        // Asking again sends the stored file: no new file, hold or charge.
        $this->press($button, 1601);
        $this->assertStringContainsString('هزینه‌ای ندارد', $this->lastReply());
        Queue::assertPushed(GenerateCatalogueArtifact::class, 1);
        Queue::assertPushed(DeliverCatalogueArtifact::class, 2);
        $this->assertSame(98, $this->wallet()->fresh()->balance_cached);
        $this->assertSame(2, CreditLedgerEntry::query()->where('related_type', CreditLedgerEntry::RELATED_ARTIFACT)->count());
        $this->assertSame([], $this->mismatches());

        // The charge is named in /balance and counted for the project.
        $this->message(['text' => '/balance'], 1601);
        $this->assertStringContainsString('• 2 اعتبار: کاتالوگ PDF، پروژه «مجموعه آبان»', $this->lastReply());
        $this->message(['text' => '/projects'], 1601);
        $this->assertStringContainsString('2 اعتبار', $this->lastReply());
    }

    public function test_a_stored_file_that_is_lost_is_made_again_without_a_new_charge(): void
    {
        $project = $this->approvedBatch(1607);
        $this->press("project:pdf:{$project->public_id}:1", 1607);
        $this->generate();
        $artifact = Artifact::query()->sole();
        Storage::disk('assets')->delete($artifact->storage_key);

        $this->deliver();

        $this->assertStringContainsString('دوباره ساخته می‌شود؛ هزینه تازه‌ای ندارد', $this->lastReply());
        $this->assertSame(Artifact::STATUS_QUEUED, $artifact->fresh()->status);
        $this->generate();
        $this->assertSame(Artifact::STATUS_READY, $artifact->fresh()->status);
        $this->deliver();
        $this->assertStringStartsWith('%PDF-', $this->documents()[0]['document']);
        $this->assertSame(98, $this->wallet()->fresh()->balance_cached);
        $this->assertSame(1, CreditLedgerEntry::query()->where('type', 'capture')->count());
        $this->assertSame([], $this->mismatches());
    }

    public function test_a_batch_reopened_while_its_file_renders_is_not_charged(): void
    {
        $project = $this->approvedBatch(1608);
        $this->press("project:pdf:{$project->public_id}:1", 1608);
        // The batch leaves approval after the snapshot was read, while the photo is loaded.
        Asset::retrieved(fn () => DB::table('projects')->where('id', $project->id)->update(['state' => 'review_required']));

        $this->generate();

        $artifact = Artifact::query()->sole();
        $this->assertSame([Artifact::STATUS_FAILED, 'SNAPSHOT_NOT_CURRENT'], [$artifact->status, $artifact->error_code]);
        $this->assertNull($artifact->storage_key);
        $this->assertSame([100, 100], [$this->wallet()->fresh()->balance_cached, $this->wallet()->fresh()->available()]);
        Queue::assertNotPushed(DeliverCatalogueArtifact::class);
    }

    public function test_a_file_whose_job_was_lost_fails_without_charge(): void
    {
        $project = $this->approvedBatch(1609);
        $this->press("project:pdf:{$project->public_id}:1", 1609);
        $this->assertSame([], $this->mismatches());

        $this->travel(31)->minutes();
        $this->artisan('catalogue:expire')->assertSuccessful();

        $this->assertSame('GENERATION_TIMED_OUT', Artifact::query()->sole()->error_code);
        $this->assertSame(100, $this->wallet()->fresh()->available());
        $this->assertSame([], $this->mismatches());
        // The job running late makes nothing.
        $this->generate();
        $this->assertSame(Artifact::STATUS_FAILED, Artifact::query()->sole()->status);
    }

    public function test_a_file_that_cannot_be_made_costs_nothing_and_can_be_asked_for_again(): void
    {
        $project = $this->approvedBatch(1602);
        $button = "project:pdf:{$project->public_id}:1";
        $this->press($button, 1602);
        $job = Queue::pushed(GenerateCatalogueArtifact::class)->last();
        // The stored photo is gone: every try fails, then the job gives up.
        Storage::disk('assets')->deleteDirectory('ws');
        try {
            $this->generate();
            $this->fail('A missing photo must throw so the job retries.');
        } catch (RuntimeException) {
        }
        $job->failed(new RuntimeException('gone'));

        $artifact = Artifact::query()->sole();
        $this->assertSame([Artifact::STATUS_FAILED, 'GENERATION_FAILED'], [$artifact->status, $artifact->error_code]);
        $this->assertSame([100, 100], [$this->wallet()->fresh()->balance_cached, $this->wallet()->fresh()->available()]);
        $this->assertStringContainsString('اعتباری کسر نشد', $this->lastReply());
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', 'capture')->count());

        // Asked again: a new hold for the same file row.
        $this->press($button, 1602);
        $this->assertSame(Artifact::STATUS_QUEUED, $artifact->fresh()->status);
        $this->assertSame(98, $this->wallet()->fresh()->available());
        Queue::assertPushed(GenerateCatalogueArtifact::class, 2);
    }

    public function test_without_enough_credit_nothing_is_held_or_made(): void
    {
        config(['karmandeman.credits.catalogue_pack' => 101]);
        $project = $this->approvedBatch(1603);

        $this->press("project:pdf:{$project->public_id}:1", 1603);

        $this->assertStringContainsString('101 اعتبار لازم است', $this->lastReply());
        $this->assertDatabaseCount('artifacts', 0);
        $this->assertSame(100, $this->wallet()->fresh()->available());
        Queue::assertNotPushed(GenerateCatalogueArtifact::class);
    }

    public function test_an_edit_needs_a_new_file_and_an_old_button_or_another_seller_gets_nothing(): void
    {
        $project = $this->approvedBatch(1604);
        $this->press("project:pdf:{$project->public_id}:1", 1604);
        $this->generate();

        // Another seller pressing this button reaches nothing.
        $this->seller(1605);
        $this->press("project:pdf:{$project->public_id}:1", 1605);
        $this->assertSame('پروژه یافت نشد.', $this->lastReply());

        // Reopen and approve again: a new snapshot, whose file is made and charged anew.
        $this->press("project:reopen:{$project->public_id}:{$project->fresh()->version}", 1604);
        $project->refresh();
        $this->message(['text' => '/done'], 1604);
        $this->press("project:approve:{$project->public_id}:{$project->fresh()->version}", 1604);
        $this->press("project:pdf:{$project->public_id}:1", 1604);
        $this->assertStringContainsString('نسخه نهایی تازه‌تری', $this->lastReply());
        $this->press("project:pdf:{$project->public_id}:2", 1604);
        $this->assertSame(2, Artifact::query()->count());
        $this->assertSame(96, Wallet::query()->whereHas('workspace', fn ($q) => $q->whereKey($project->workspace_id))->sole()->available());
    }

    public function test_a_snapshot_replaced_before_generation_fails_without_charge(): void
    {
        $project = $this->approvedBatch(1606);
        $this->press("project:pdf:{$project->public_id}:1", 1606);
        $this->press("project:reopen:{$project->public_id}:{$project->fresh()->version}", 1606);

        $this->generate();

        $this->assertSame('SNAPSHOT_NOT_CURRENT', Artifact::query()->sole()->error_code);
        $this->assertSame(100, $this->wallet()->fresh()->available());
        $this->assertStringContainsString('نسخه نهایی پروژه پس از درخواست تغییر کرد', $this->lastReply());
    }
}
