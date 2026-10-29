<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Privacy\PruneRetentionAction;
use App\Actions\Project\ImportTelegramPhotoAction;
use App\Data\Telegram\TelegramUserData;
use App\Exceptions\TelegramFileTooLarge;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Asset;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Photos sent to the bot become private batch assets (ADR-024). */
class TelegramPhotoIntakeTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const TOKEN = '123:test-token';

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => self::TOKEN,
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class]);
    }

    private function fakeTelegram(string $bytes, ?int $declaredSize = null): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => [
                'file_id' => 'x', 'file_path' => 'photos/file_7.jpg', 'file_size' => $declaredSize ?? strlen($bytes),
            ]]),
            'api.telegram.org/file/bot*' => Http::response($bytes),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    private function send(array $message, int $telegramId, ?int $updateId = null): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => $updateId ?? ++$this->updates,
            'message' => $message + [
                'message_id' => ++$this->updates, 'date' => time(),
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function seller(int $telegramId, bool $project = true): User
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
        if ($project) {
            $this->send(['text' => '/new Batch'], $telegramId)->assertOk();
        }

        return $user;
    }

    private function photo(string $suffix = 'a'): array
    {
        return ['photo' => [
            ['file_id' => "small-{$suffix}", 'file_unique_id' => 's', 'width' => 90, 'height' => 60, 'file_size' => 900],
            ['file_id' => "large-{$suffix}", 'file_unique_id' => 'l', 'width' => 1280, 'height' => 853, 'file_size' => 90_000],
        ]];
    }

    private function replied(string $fragment): void
    {
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, $fragment));
    }

    public function test_photo_in_the_open_batch_is_fetched_by_the_server_and_stored_encrypted(): void
    {
        $user = $this->seller(980);
        $bytes = ImageFixture::make(1);
        $this->fakeTelegram($bytes);

        $this->send($this->photo() + ['caption' => '/delete_data'], 980)->assertOk();

        // The largest size is fetched, server-side, with the bot token kept in the URL only.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getFile') && $request['file_id'] === 'large-a');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/file/bot'.self::TOKEN.'/photos/file_7.jpg');
        $asset = Asset::query()->sole();
        $this->assertSame(Asset::SOURCE_TELEGRAM, $asset->source);
        $this->assertNotNull($asset->source_message_key);
        $this->assertStringStartsWith('KMB1', Storage::disk('assets')->get($asset->storage_key));
        $this->assertSame(0, TelegramMediaIntake::query()->count());
        $this->replied('اضافه شد');
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_contains($job->text, 'حذف'));

        Sanctum::actingAs($user);
        $this->assertSame($bytes, $this->get("/api/v1/assets/{$asset->public_id}/download")->assertOk()->getContent());
        $project = Project::query()->sole();
        $this->assertSame(2, $project->version);
    }

    public function test_repeated_update_and_same_photo_again_keep_one_asset(): void
    {
        $this->seller(981);
        $this->fakeTelegram(ImageFixture::make(2, 'png'));

        $this->send($this->photo('a'), 981, 50_001)->assertOk();
        $this->send($this->photo('a'), 981, 50_001)->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->send($this->photo('b'), 981)->assertOk();

        $this->assertSame(1, Asset::query()->count());
        $this->replied('قبلاً');
        Http::assertSentCount(4);
    }

    public function test_refusals_fetch_nothing(): void
    {
        $this->seller(982, project: false);
        $this->fakeTelegram(ImageFixture::make(3));

        $this->send($this->photo(), 982)->assertOk();
        $this->replied('/new');

        $this->send(['text' => '/new Batch'], 982)->assertOk();
        $this->send(['document' => ['file_id' => 'doc', 'mime_type' => 'application/pdf', 'file_size' => 100]], 982)->assertOk();
        $this->replied('JPEG');
        $this->send(['document' => ['file_id' => 'big', 'mime_type' => 'image/jpeg', 'file_size' => 11 * 1024 * 1024]], 982)->assertOk();
        $this->replied('۱۰ مگابایت');

        Http::assertNothingSent();
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(0, TelegramMediaIntake::query()->count());
    }

    public function test_queue_payload_and_pending_row_hold_no_file_id_or_chat_id(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $this->seller(983);
        $this->send(['document' => ['file_id' => 'secret-file-id-983', 'mime_type' => 'image/png', 'file_size' => 500]], 983)->assertOk();

        Queue::assertPushed(ImportTelegramPhoto::class, fn (ImportTelegramPhoto $job) => ! str_contains(serialize($job), 'secret-file-id')
            && ! str_contains(serialize($job), '983'));
        $row = json_encode(DB::table('telegram_media_intakes')->sole(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-file-id', $row);
        $this->assertStringNotContainsString('"983"', $row);
    }

    public function test_erasure_after_queueing_stops_the_download_and_retention_drops_stuck_rows(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $user = $this->seller(984);
        $this->fakeTelegram(ImageFixture::make(4));
        $this->send($this->photo('a'), 984)->assertOk();
        $this->send($this->photo('b'), 984)->assertOk();
        [$first, $second] = TelegramMediaIntake::query()->orderBy('id')->pluck('id')->all();

        Workspace::query()->whereKey($user->workspaces()->value('workspaces.id'))->update(['status' => 'deletion_pending']);
        app(ImportTelegramPhotoAction::class)->execute($first);
        Http::assertNothingSent();
        $this->assertSame([$second], TelegramMediaIntake::query()->pluck('id')->all());

        $this->travel(25)->hours();
        $this->assertSame(1, app(PruneRetentionAction::class)->execute(now()->toImmutable())['media_intakes']);
        $this->assertSame(0, TelegramMediaIntake::query()->count());
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_transport_failure_retries_without_the_token_then_tells_the_user(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $this->seller(985);
        Http::fake(['*' => Http::response(['ok' => false], 502)]);
        $this->send($this->photo(), 985)->assertOk();
        $id = TelegramMediaIntake::query()->value('id');

        try {
            app(ImportTelegramPhotoAction::class)->execute($id);
            $this->fail('A failed lookup must be retried.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage().$e->getTraceAsString());
        }
        $this->assertSame(1, TelegramMediaIntake::query()->count());

        (new ImportTelegramPhoto($id))->failed(new RuntimeException('x'));
        $this->assertSame(0, TelegramMediaIntake::query()->count());
        $this->replied('ناموفق');
    }

    public function test_downloaded_bytes_are_checked_like_an_upload(): void
    {
        $this->seller(986);
        // Telegram declares a small file, then sends more than the cap.
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/f.jpg', 'file_size' => 100]]),
            'api.telegram.org/file/bot*' => Http::sequence()
                ->push(ImageFixture::make(5).str_repeat("\0", 2048))
                ->push('<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ]);
        config(['karmandeman.assets.max_kilobytes' => 1]);
        $this->send(['document' => ['file_id' => 'no-size', 'mime_type' => 'image/jpeg']], 986)->assertOk();
        $this->replied('۱۰ مگابایت');

        config(['karmandeman.assets.max_kilobytes' => 10240]);
        $this->send($this->photo('b'), 986)->assertOk();
        $this->replied('JPEG');

        $this->assertSame(0, Asset::query()->count());
        $this->assertSame([], Storage::disk('assets')->allFiles());
        $this->assertSame(0, TelegramMediaIntake::query()->count());
    }

    public function test_bot_photos_share_the_upload_rate_limit(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        config(['karmandeman.assets.uploads_per_minute' => 2]);
        $this->seller(987);
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->send($this->photo($suffix), 987)->assertOk();
        }

        $this->assertSame(2, TelegramMediaIntake::query()->count());
        Queue::assertPushed(ImportTelegramPhoto::class, 2);
        $this->replied('صبر کن');
    }

    public function test_batch_closed_after_queueing_takes_no_photo(): void
    {
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $this->seller(988);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->send($this->photo(), 988)->assertOk();
        Project::query()->update(['state' => 'review_required']);

        app(ImportTelegramPhotoAction::class)->execute(TelegramMediaIntake::query()->value('id'));

        Http::assertNothingSent();
        $this->replied('دیگر عکس نمی‌پذیرد');
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame(0, TelegramMediaIntake::query()->count());
    }

    public function test_size_refusal_wrapped_by_the_http_client_is_still_a_size_refusal(): void
    {
        Http::fake([
            'api.telegram.org/file/bot*' => fn () => throw new ConnectionException('x', 0, new RuntimeException('y', 0, new TelegramFileTooLarge)),
        ]);

        $this->expectException(TelegramFileTooLarge::class);
        app(TelegramBotService::class)->downloadFile('photos/f.jpg', 1024);
    }
}
