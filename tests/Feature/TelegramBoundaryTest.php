<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class TelegramBoundaryTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private bool $transportFails = false;

    private const TOKEN = '123:only-a-test-token';

    private const HEADERS = ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];

    private const AUTH_HEADERS = ['Idempotency-Key' => 'telegram-boundary-1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config(['services.telegram.bot_token' => self::TOKEN, 'services.telegram.webhook_secret' => 'test-secret']);
        Http::fake(['api.telegram.org/*' => fn () => Http::response(['ok' => ! $this->transportFails], $this->transportFails ? 500 : 200)]);
    }

    private function signed(array $user, ?int $date = null): string
    {
        $fields = ['auth_date' => (string) ($date ?? time()), 'user' => json_encode($user)];
        ksort($fields);
        $check = implode("\n", array_map(fn ($key, $value) => "$key=$value", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', self::TOKEN, 'WebAppData', true));

        return http_build_query($fields);
    }

    private function update(int $id = 1, string $command = '/start'): array
    {
        return ['update_id' => $id, 'message' => [
            'text' => $command, 'from' => ['id' => 777, 'first_name' => 'Test'],
            'chat' => ['id' => 777, 'type' => 'private'],
        ]];
    }

    private function readyForProject(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(777));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');
    }

    public function test_expired_future_duplicate_and_array_init_data_are_rejected(): void
    {
        foreach ([
            $this->signed(['id' => 777], time() - 301),
            $this->signed(['id' => 777], time() + 300),
            $this->signed(['id' => 777]).'&auth_date='.time(),
            'hash=bad&user[]=unexpected&auth_date=1',
        ] as $payload) {
            $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $payload], self::AUTH_HEADERS)->assertUnauthorized();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signed_but_invalid_user_fields_never_create_an_account(): void
    {
        foreach ([['id' => 0], ['id' => -1], ['id' => '777'], ['id' => 777, 'first_name' => ['bad']], ['id' => 777, 'username' => str_repeat('x', 256)]] as $user) {
            $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $this->signed($user)], self::AUTH_HEADERS)->assertUnauthorized();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_tokens_expire_and_suspended_identity_cannot_mint_tokens(): void
    {
        app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(777));
        $payload = ['initData' => $this->signed(['id' => 777])];
        $this->postJson('/api/v1/auth/telegram/verify', $payload, self::AUTH_HEADERS)->assertOk();
        $this->assertNotNull(DB::table('personal_access_tokens')->value('expires_at'));
        $expires = DB::table('personal_access_tokens')->value('expires_at');
        $this->assertTrue(now()->addHours(9)->greaterThan($expires));
        DB::table('users')->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/telegram/verify', $payload, self::AUTH_HEADERS)->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_bearer_token_is_rejected(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(998));
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_long_telegram_project_name_does_not_poison_retries(): void
    {
        $this->postJson('/api/webhooks/telegram', $this->update(10, '/new '.str_repeat('x', 256)), self::HEADERS)->assertOk();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_auth_rate_limit_preserves_error_envelope_and_retry_header(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/telegram/verify', ['initData' => 'bad'], self::AUTH_HEADERS)->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => 'bad'], self::AUTH_HEADERS)
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_invalid_webhooks_are_rejected_before_persistence(): void
    {
        foreach ([[], ['update_id' => []], ['update_id' => -1], ['update_id' => '1'], ['update_id' => 1, 'message' => ['text' => '/start']]] as $payload) {
            $this->postJson('/api/webhooks/telegram', $payload, self::HEADERS)->assertUnprocessable();
        }
        $this->assertDatabaseCount('conversation_events', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_group_and_mismatched_private_chat_cannot_receive_workspace_data(): void
    {
        $group = $this->update(1, '/balance');
        $group['message']['chat'] = ['id' => -123, 'type' => 'group'];
        $mismatch = $this->update(2, '/balance');
        $mismatch['message']['chat']['id'] = 888;
        foreach ([$group, $mismatch] as $payload) {
            $this->postJson('/api/webhooks/telegram', $payload, self::HEADERS)->assertOk();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_failure_rolls_back_dedupe_and_domain_then_retry_can_succeed(): void
    {
        $this->readyForProject();
        $repository = app(ProjectRepositoryInterface::class);
        $mock = Mockery::mock(ProjectRepositoryInterface::class);
        $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('simulated failure'));
        $this->app->instance(ProjectRepositoryInterface::class, $mock);
        $this->postJson('/api/webhooks/telegram', $this->update(1, '/new'), self::HEADERS)
            ->assertStatus(503)->assertJsonPath('error.code', 'TELEGRAM_UPDATE_FAILED');
        $this->assertDatabaseCount('conversation_events', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->app->instance(ProjectRepositoryInterface::class, $repository);
        $this->postJson('/api/webhooks/telegram', $this->update(1, '/new'), self::HEADERS)->assertOk();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_duplicate_update_zero_queues_one_encrypted_reply_and_worker_delivers_it(): void
    {
        $this->postJson('/api/webhooks/telegram', $this->update(0), self::HEADERS)->assertOk();
        $this->postJson('/api/webhooks/telegram', $this->update(0), self::HEADERS)
            ->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
        Artisan::call('queue:work', ['connection' => 'telegram', '--queue' => 'telegram', '--once' => true]);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
    }

    public function test_transport_failure_is_retriable_without_sensitive_exception_details(): void
    {
        $this->transportFails = true;
        try {
            app(TelegramBotService::class)->sendMessage(777, 'private text');
            $this->fail('Expected delivery failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram delivery failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_failed_delivery_stays_queued_without_repeating_project_creation(): void
    {
        $this->readyForProject();
        $this->transportFails = true;
        $this->postJson('/api/webhooks/telegram', $this->update(3, '/new'), self::HEADERS)->assertOk();
        Artisan::call('queue:work', ['connection' => 'telegram', '--queue' => 'telegram', '--once' => true]);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
        $this->postJson('/api/webhooks/telegram', $this->update(3, '/new'), self::HEADERS)->assertJsonPath('data.status', 'duplicate');
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('jobs', 1);
    }
}
