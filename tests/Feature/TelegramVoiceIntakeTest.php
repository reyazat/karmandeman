<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Project\ImportTelegramPhotoAction;
use App\Data\Telegram\TelegramUserData;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\VoiceAsset;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\OggFixture;
use Tests\TestCase;

final class TelegramVoiceIntakeTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $updates = 0;

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(voiceIntake: true);
        config(['karmandeman.voice.intake_enabled' => true]);
        config(['services.telegram.webhook_secret' => self::SECRET, 'services.telegram.bot_token' => '123:test-token']);
        Queue::fake([SendTelegramMessage::class]);
        Storage::fake('assets');
        Http::fake([
            'api.telegram.org/bot*/getFile' => fn () => Http::response(['ok' => true, 'result' => [
                'file_id' => 'voice-id', 'file_path' => 'voice/source.ogg', 'file_size' => strlen($this->file),
            ]]),
            'api.telegram.org/file/bot*' => fn () => Http::response($this->file),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    private function seller(int $id): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($id));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
        Sanctum::actingAs($user);
        $this->message($id, ['text' => '/new Voice'])->assertOk();
    }

    private function message(int $seller, array $payload, ?int $update = null): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => $update ?? ++$this->updates,
            'message' => $payload + [
                'message_id' => ++$this->updates, 'date' => time(),
                'from' => ['id' => $seller, 'first_name' => 'U'],
                'chat' => ['id' => $seller, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function voice(?int $size = null, int $duration = 1): array
    {
        return ['voice' => [
            'file_id' => 'voice-id', 'file_unique_id' => 'unique-voice', 'duration' => $duration,
            'mime_type' => 'audio/ogg', 'file_size' => $size ?? strlen($this->file),
        ]];
    }

    public function test_bot_voice_is_checked_stored_privately_and_not_sent_to_openai(): void
    {
        $this->seller(6210);
        $this->file = OggFixture::make();
        $this->message(6210, $this->voice())->assertOk();
        $asset = VoiceAsset::query()->sole();
        $this->assertSame(VoiceAsset::SOURCE_TELEGRAM, $asset->source);
        $this->assertNotNull($asset->source_message_key);
        $this->assertStringStartsWith('KMB1', Storage::disk('assets')->get($asset->storage_key));
        $this->assertDatabaseCount('telegram_media_intakes', 0);
        $this->assertDatabaseCount('ai_workflow_runs', 0);
        $this->assertSame(0, DB::table('credit_ledger')->where('type', 'capture')->count());
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'تبدیل به متن هنوز فعال نیست'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getFile') && $request['file_id'] === 'voice-id');

        $this->message(6210, $this->voice())->assertOk();
        $this->assertDatabaseCount('voice_assets', 1);
        $this->assertCount(1, Storage::disk('assets')->allFiles());
        $this->assertSame($this->file, $this->get("/api/v1/voices/{$asset->public_id}/download")->assertOk()->getContent());

        $this->message(6210, ['text' => '/projects'])->assertOk();
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '0 فایل CSV، 1 ویس، 0 محصول'));
    }

    public function test_a_voice_queued_before_intake_was_switched_off_is_never_downloaded(): void
    {
        $this->seller(6212);
        $this->file = OggFixture::make();
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
        $this->message(6212, $this->voice())->assertOk();
        $intake = DB::table('telegram_media_intakes')->sole();

        config(['karmandeman.voice.intake_enabled' => false]);
        app(ImportTelegramPhotoAction::class)->execute($intake->id);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/getFile'));
        $this->assertDatabaseCount('voice_assets', 0);
        $this->assertDatabaseCount('telegram_media_intakes', 0);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'دریافت ویس هنوز فعال نیست'));
    }

    public function test_bot_rejects_declared_or_actual_oversized_and_damaged_voices(): void
    {
        $this->seller(6211);
        $this->file = OggFixture::make();
        $this->message(6211, $this->voice(size: 3 * 1024 * 1024))->assertOk();
        $this->message(6211, $this->voice(duration: 200))->assertOk();
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/getFile'));

        config(['karmandeman.voice.max_seconds' => 0]);
        $this->message(6211, $this->voice(duration: 0))->assertOk();
        $this->assertDatabaseCount('voice_assets', 0);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'حداکثر دو دقیقه'));

        config(['karmandeman.voice.max_seconds' => 120]);
        $this->file = 'not an ogg';
        $this->message(6211, $this->voice())->assertOk();
        $this->assertDatabaseCount('voice_assets', 0);
        $this->assertCount(0, Storage::disk('assets')->allFiles());
    }
}
