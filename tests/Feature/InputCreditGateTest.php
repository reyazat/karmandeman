<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Wallet\ReserveCreditsAction;
use App\Data\Telegram\TelegramUserData;
use App\Enums\CreditLedgerType;
use App\Jobs\Project\ImportTelegramPhoto;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** New batch input needs spendable credit; reading, review and erasure do not (ADR-048). */
final class InputCreditGateTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'karmandeman.gift_credits' => 0,
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        Storage::fake('assets');
        Http::fake(['*' => Http::response(['ok' => true])]);
        Queue::fake([SendTelegramMessage::class, ImportTelegramPhoto::class]);
    }

    private function seller(int $telegramId): User
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = $this->workspace($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function workspace(User $user): Workspace
    {
        return app(WorkspaceRepositoryInterface::class)->currentFor($user);
    }

    private function grant(User $user, int $credits): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $wallets->appendEntry($wallets->ensureWallet($this->workspace($user)), CreditLedgerType::Grant, $credits, 'test-grant-'.++$this->keys);
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-key-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function photo(string $project): TestResponse
    {
        return $this->post("/api/v1/projects/{$project}/assets", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', ImageFixture::make(++$this->keys)),
        ], ['Idempotency-Key' => 'asset-key-'.$this->keys, 'Accept' => 'application/json']);
    }

    private function bot(int $telegramId, array $message): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->keys,
            'message' => $message + [
                'message_id' => ++$this->keys, 'date' => time(),
                'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_the_api_takes_no_new_input_without_spendable_credit(): void
    {
        $user = $this->seller(7001);
        $project = $this->project();

        $this->photo($project)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_REQUIRED');
        $this->post("/api/v1/projects/{$project}/tables", [
            'file' => UploadedFile::fake()->createWithContent('products.csv', "name,price\nکیف,1000\n"),
        ], ['Idempotency-Key' => 'table-key-1', 'Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_REQUIRED');
        $this->postJson("/api/v1/projects/{$project}/products", ['name' => ['value' => 'کیف', 'status' => 'confirmed']], ['Idempotency-Key' => 'product-key-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_REQUIRED');
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('product_tables', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('assets')->allFiles());
        // Reading stays open.
        $this->getJson("/api/v1/projects/{$project}")->assertOk();
        $this->getJson('/api/v1/wallet/ledger')->assertOk();

        // Credit held by a run is not spendable either.
        $this->grant($user, 2);
        DB::transaction(fn () => app(ReserveCreditsAction::class)->execute($this->workspace($user), 2, 'test-hold', 'workspace', 1, 'product_extraction'));
        $this->photo($project)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_REQUIRED');

        // Gift and bought credit both open input; nothing is spent by storing it.
        $this->grant($user, 1);
        $this->photo($project)->assertCreated();
        $this->assertSame(1, app(WalletRepositoryInterface::class)->forWorkspace($this->workspace($user))?->available());
    }

    public function test_the_bot_refuses_input_before_any_download_and_keeps_reading_open(): void
    {
        $this->seller(7002);
        $this->bot(7002, ['photo' => [['file_id' => 'p-1', 'file_unique_id' => 'u-1', 'width' => 40, 'height' => 30, 'file_size' => 500]]]);
        $this->bot(7002, ['document' => ['file_id' => 'd-1', 'file_unique_id' => 'u-2', 'file_name' => 'products.csv', 'mime_type' => 'text/csv', 'file_size' => 50]]);
        $this->bot(7002, ['text' => "/product کیف چرم\n۱۲۰٬۰۰۰ تومان"]);

        $this->assertDatabaseCount('telegram_media_intakes', 0);
        $this->assertDatabaseCount('products', 0);
        Queue::assertNotPushed(ImportTelegramPhoto::class);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/getFile'));
        $this->assertCount(3, Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'اعتبار قابل استفاده‌ات تمام شده است')));

        $this->bot(7002, ['text' => '/balance']);
        $this->bot(7002, ['text' => '/help']);
        $this->assertCount(3, Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'اعتبار قابل استفاده‌ات تمام شده است')));
    }
}
