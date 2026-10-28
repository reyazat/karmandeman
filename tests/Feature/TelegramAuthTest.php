<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
{
    private const BOT_TOKEN = '123:test-token';

    private const HEADERS = ['Idempotency-Key' => 'telegram-auth-key-1'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'karmandeman.gift_credits' => 50,
        ]);

        Http::fake();
    }

    private function botUser(int $telegramId): User
    {
        return app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
    }

    private function buildInitData(array $user): string
    {
        $fields = [
            'auth_date' => (string) time(),
            'query_id' => 'AAtest',
            'user' => json_encode($user),
        ];

        ksort($fields);
        $checkString = implode("\n", array_map(
            fn (string $k, string $v): string => "$k=$v",
            array_keys($fields),
            $fields,
        ));

        $secret = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $checkString, $secret);

        return http_build_query($fields);
    }

    public function test_valid_init_data_signs_in_a_bot_registered_user_without_new_gift(): void
    {
        $user = $this->botUser(777);
        $initData = $this->buildInitData(['id' => 777, 'first_name' => 'مریم']);

        $response = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id']]])
            ->assertJsonPath('data.user.id', $user->public_id)
            ->assertJsonPath('meta.api_version', 'v1');
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertTrue(Str::isUuid($response->json('data.user.id')));
    }

    public function test_sign_in_registration_fails_inside_its_transaction_for_a_purged_account(): void
    {
        // The existence check and the "no create" decision are one locked step,
        // so a purge committing just before sign-in cannot yield a new account (ADR-012).
        try {
            app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(780), create: false);
            $this->fail('Sign-in created an account.');
        } catch (\DomainException $e) {
            $this->assertSame('ACCOUNT_NOT_FOUND', $e->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_unknown_telegram_user_gets_no_account_from_mini_app(): void
    {
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $this->buildInitData(['id' => 779])], self::HEADERS)
            ->assertNotFound()->assertJsonPath('error.code', 'ACCOUNT_NOT_FOUND');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_forged_init_data_is_rejected(): void
    {
        $initData = $this->buildInitData(['id' => 777]);
        $forged = $initData.'&auth_date=0';

        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $forged], self::HEADERS)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TELEGRAM_INIT_DATA_INVALID');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_me_requires_auth_and_returns_user(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id);
    }

    public function test_wallet_ledger_is_scoped_to_own_workspace(): void
    {
        $this->botUser(42);
        $initData = $this->buildInitData(['id' => 42, 'first_name' => 'A']);
        $token = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS)
            ->json('data.token');

        $response = $this->getJson('/api/v1/wallet/ledger', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJsonPath('data.balance', 50)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.type', 'grant');
        $this->assertTrue(Str::isUuid($response->json('data.entries.0.id')));
    }

    public function test_token_request_replay_and_conflict_are_scoped_to_verified_user(): void
    {
        $this->botUser(777);
        $this->botUser(778);
        $initData = $this->buildInitData(['id' => 777, 'first_name' => 'A']);
        $first = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS)
            ->assertOk()->json('data.token');
        $replayed = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS)
            ->assertOk()->json('data.token');
        $this->assertSame($first, $replayed);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('telegram_auth_requests', 1);
        $stored = DB::table('telegram_auth_requests')->value('encrypted_token');
        $this->assertStringNotContainsString($first, $stored);

        $changed = $this->buildInitData(['id' => 777, 'first_name' => 'Changed']);
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $changed], self::HEADERS)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $changed], ['Idempotency-Key' => 'telegram-auth-key-2'])
            ->assertOk();
        $this->postJson('/api/v1/auth/telegram/verify', [
            'initData' => $this->buildInitData(['id' => 778]),
        ], self::HEADERS)->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 3);
    }

    public function test_auth_requires_key_and_revoked_token_cannot_be_replayed(): void
    {
        $this->botUser(777);
        $initData = $this->buildInitData(['id' => 777]);
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData])
            ->assertUnprocessable();
        $first = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS)
            ->assertOk()->json('data.token');
        $this->withToken($first)->getJson('/api/v1/me')->assertOk();
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], self::HEADERS)
            ->assertStatus(409)->assertJsonPath('error.code', 'AUTH_REPLAY_EXPIRED');
        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData], ['Idempotency-Key' => 'telegram-auth-key-2'])
            ->assertOk();
    }
}
