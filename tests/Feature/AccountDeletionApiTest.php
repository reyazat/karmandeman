<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\AccountDeletion;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AccountDeletionApiTest extends TestCase
{
    private const BOT_TOKEN = '123:test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => self::BOT_TOKEN, 'karmandeman.gift_credits' => 50]);
        Http::fake();
    }

    private function initData(int $telegramId, ?int $authDate = null): string
    {
        $fields = [
            'auth_date' => (string) ($authDate ?? time()),
            'query_id' => 'AAtest',
            'user' => json_encode(['id' => $telegramId, 'first_name' => 'Seller']),
        ];
        ksort($fields);
        $check = implode("\n", array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true));

        return http_build_query($fields);
    }

    private function account(int $telegramId): User
    {
        return app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
    }

    public function test_reauthenticated_owner_starts_the_shared_deletion_once(): void
    {
        $user = $this->account(600);
        $user->createToken('mini-app');
        Sanctum::actingAs($user);
        $this->freezeSecond();

        $response = $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(600), 'confirm' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.requested_at', now()->toIso8601String())
            ->assertJsonPath('data.deadline_at', now()->addHours(168)->toIso8601String());

        $deletion = AccountDeletion::query()->sole();
        $this->assertSame($deletion->public_id, $response->json('data.id'));
        $this->assertSame('mini_app', $deletion->channel);
        $this->assertSame('deletion_pending', $user->workspaces()->first()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/telegram/verify', ['initData' => $this->initData(600)], ['Idempotency-Key' => 'reopen-after-delete'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_DELETION_PENDING')
            ->assertJsonPath('error.details.deletion.id', $deletion->public_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_missing_confirmation_or_stale_or_forged_reauthentication_changes_nothing(): void
    {
        Sanctum::actingAs($this->account(601));

        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(601)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(601), 'confirm' => false])
            ->assertUnprocessable();
        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(601, time() - 301), 'confirm' => true])
            ->assertUnauthorized()->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(601).'x', 'confirm' => true])
            ->assertUnauthorized()->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');

        $this->assertDatabaseCount('account_deletions', 0);
    }

    public function test_reauthentication_of_another_account_cannot_delete_either_tenant(): void
    {
        $owner = $this->account(602);
        $this->account(603);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(603), 'confirm' => true])
            ->assertForbidden();
        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(604), 'confirm' => true])
            ->assertForbidden();

        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertSame(2, User::query()->whereHas('workspaces', fn ($q) => $q->where('status', 'active'))->count());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_guest_cannot_request_deletion(): void
    {
        $this->postJson('/api/v1/account/deletion', ['initData' => $this->initData(605), 'confirm' => true])
            ->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }
}
