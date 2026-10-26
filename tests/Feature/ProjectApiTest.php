<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function onboardedUser(int $telegramId = 900): User
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'message_id' => 1, 'date' => time(), 'text' => '/start',
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        return User::query()->latest('id')->firstOrFail();
    }

    public function test_create_and_list_projects_via_api(): void
    {
        Sanctum::actingAs($this->onboardedUser());

        $this->postJson('/api/v1/projects', ['name' => 'کیف‌های جدید'], ['Idempotency-Key' => 'project-key-1'])
            ->assertCreated()
            ->assertJsonPath('data.state', 'collecting_input')
            ->assertJsonPath('data.name', 'کیف‌های جدید');

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_projects_are_tenant_isolated(): void
    {
        $userA = $this->onboardedUser(901);
        $userB = $this->onboardedUser(902);

        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/projects', [], ['Idempotency-Key' => 'project-key-2'])->assertCreated();

        // QA-14 style: user B must not see user A's projects.
        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_new_command_creates_collecting_project(): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => 55,
            'message' => [
                'message_id' => 2, 'date' => time(), 'text' => '/new کیف جدید',
                'from' => ['id' => 555, 'first_name' => 'علی'],
                'chat' => ['id' => 555, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $this->assertDatabaseHas('projects', [
            'name' => 'کیف جدید',
            'state' => 'collecting_input',
        ]);
    }

    public function test_project_retry_returns_same_snapshot_and_reused_key_with_other_input_conflicts(): void
    {
        Sanctum::actingAs($this->onboardedUser());
        $headers = ['Idempotency-Key' => 'project-retry-1'];
        $first = $this->postJson('/api/v1/projects', ['name' => 'Original'], $headers)->assertCreated();
        $this->postJson('/api/v1/projects', ['name' => 'Original'], $headers)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/projects', ['name' => 'Changed'], $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_project_requires_key_and_keys_are_scoped_to_owner(): void
    {
        $userA = $this->onboardedUser(910);
        $userB = $this->onboardedUser(911);
        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/projects', [])->assertUnprocessable();
        $this->postJson('/api/v1/projects', ['name' => 'A'], ['Idempotency-Key' => 'same-key-1'])->assertCreated();
        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/projects', ['name' => 'B'], ['Idempotency-Key' => 'same-key-1'])->assertCreated();
        $this->getJson('/api/v1/projects')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B');
        $this->assertDatabaseCount('projects', 2);
    }
}
