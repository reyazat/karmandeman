<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'karmandeman.gift_credits' => 100,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Queue::fake([SendTelegramMessage::class]);
    }

    private function startUpdate(int $updateId = 1, int $telegramUserId = 555): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 10 + $updateId,
                'date' => time(),
                'text' => '/start',
                'from' => ['id' => $telegramUserId, 'first_name' => 'علی'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
            ],
        ];
    }

    public function test_webhook_rejects_missing_or_wrong_secret(): void
    {
        $this->postJson('/api/webhooks/telegram', $this->startUpdate())
            ->assertForbidden();

        $this->postJson('/api/webhooks/telegram', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertForbidden();
    }

    public function test_start_provisions_account_workspace_employee_and_gift_once(): void
    {
        $this->postJson('/api/webhooks/telegram', $this->startUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
        ])->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_identities', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('workspace_memberships', 1);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertDatabaseHas('credit_ledger', ['type' => 'grant', 'amount' => 100]);
    }

    public function test_repeated_start_is_idempotent(): void
    {
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];

        // QA-01: second /start (new update_id) must not duplicate anything.
        $this->postJson('/api/webhooks/telegram', $this->startUpdate(1), $headers)->assertOk();
        $this->postJson('/api/webhooks/telegram', $this->startUpdate(2), $headers)->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame(100, Wallet::sole()->balance_cached);
    }

    public function test_duplicate_update_id_is_deduplicated(): void
    {
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];

        $this->postJson('/api/webhooks/telegram', $this->startUpdate(7), $headers)->assertOk();

        $response = $this->postJson('/api/webhooks/telegram', $this->startUpdate(7), $headers);
        $response->assertOk()->assertJsonPath('data.status', 'duplicate');

        // Telegram retried the same update: no second /start processing.
        $this->assertDatabaseCount('conversation_events', 1);
        $this->assertSame(1, CreditLedgerEntry::count());
        Queue::assertPushed(SendTelegramMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_balance_command_reports_wallet(): void
    {
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];

        $this->postJson('/api/webhooks/telegram', $this->startUpdate(1), $headers);

        $balance = $this->startUpdate(2);
        $balance['message']['text'] = '/balance';
        $this->postJson('/api/webhooks/telegram', $balance, $headers)->assertOk();

        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => str_contains($job->text, '100'));
    }
}
