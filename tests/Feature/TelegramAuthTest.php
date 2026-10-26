<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
{
    private const BOT_TOKEN = '123:test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'karmandeman.gift_credits' => 50,
        ]);

        Http::fake();
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

    public function test_valid_init_data_issues_token_and_registers_user(): void
    {
        $initData = $this->buildInitData(['id' => 777, 'first_name' => 'مریم']);

        $response = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id']]])
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertDatabaseHas('user_identities', ['provider' => 'telegram', 'external_id' => '777']);
        $this->assertDatabaseHas('credit_ledger', ['amount' => 50, 'type' => 'grant']);
    }

    public function test_forged_init_data_is_rejected(): void
    {
        $initData = $this->buildInitData(['id' => 777]);
        $forged = $initData.'&auth_date=0';

        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $forged])
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
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_wallet_ledger_is_scoped_to_own_workspace(): void
    {
        $initData = $this->buildInitData(['id' => 42, 'first_name' => 'A']);
        $token = $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $initData])
            ->json('data.token');

        $response = $this->getJson('/api/v1/wallet/ledger', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJsonPath('data.balance', 50)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.type', 'grant');
    }
}
