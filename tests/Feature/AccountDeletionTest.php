<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Brand\UpdateBrandProfileAction;
use App\Actions\Identity\IssueTelegramTokenAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Privacy\RequestAccountDeletionAction;
use App\Actions\Project\CreateApiProjectAction;
use App\Data\Telegram\TelegramUserData;
use App\Enums\AccountDeletionStatus;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AccountDeletion;
use App\Models\Brand;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class AccountDeletionTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-deletion-secret';

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'karmandeman.gift_credits' => 100,
        ]);
        Queue::fake([SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function send(string $text, int $telegramId = 555): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->updateId,
            'message' => [
                'message_id' => $this->updateId, 'date' => time(), 'text' => $text,
                'from' => ['id' => $telegramId, 'first_name' => 'Seller'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function pressButton(string $data, int $telegramId = 555): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->updateId,
            'callback_query' => [
                'id' => 'callback-'.$this->updateId,
                'from' => ['id' => $telegramId, 'first_name' => 'Seller'],
                'message' => ['chat' => ['id' => $telegramId, 'type' => 'private']],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function lastReply(): SendTelegramMessage
    {
        return Queue::pushed(SendTelegramMessage::class)->last();
    }

    private function onboard(int $telegramId = 555): Workspace
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', "Brand {$telegramId}");
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');

        return $workspace;
    }

    private function confirmationButton(int $telegramId = 555): string
    {
        $this->send('/delete_data', $telegramId)->assertOk();

        return $this->lastReply()->buttons[0][0]['callback_data'];
    }

    public function test_prompt_requires_explicit_confirmation_and_cancel_changes_nothing(): void
    {
        $workspace = $this->onboard();
        $this->send('/delete_data')->assertOk();
        $reply = $this->lastReply();
        $this->assertStringContainsString('برگشت‌ناپذیر', $reply->text);
        $this->assertStringStartsWith('delete:confirm:', $reply->buttons[0][0]['callback_data']);
        $this->assertSame('delete:cancel', $reply->buttons[1][0]['callback_data']);
        $this->assertDatabaseCount('account_deletions', 0);

        $this->pressButton('delete:cancel')->assertOk();
        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertSame('active', $workspace->fresh()->status);
        $this->send('/new Still works')->assertOk();
        $this->assertPrivateValue(Project::class, [], 'name', 'Still works');
    }

    public function test_confirmation_contains_account_from_t0_and_replays_one_receipt(): void
    {
        $workspace = $this->onboard();
        $user = User::query()->sole();
        [, $token] = app(IssueTelegramTokenAction::class)->execute(new TelegramUserData(555), 'init-data-fixture', 'auth-key-1');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->freezeSecond();

        $confirm = $this->confirmationButton();
        $this->pressButton($confirm)->assertOk();

        $deletion = AccountDeletion::query()->sole();
        $this->assertSame($user->id, $deletion->user_id);
        $this->assertSame(AccountDeletionStatus::Requested, $deletion->status);
        $this->assertSame('telegram', $deletion->channel);
        $this->assertEquals(now(), $deletion->requested_at);
        $this->assertEquals(now()->addHours(168), $deletion->deadline_at);
        $this->assertSame('deletion_pending', $workspace->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('telegram_auth_requests', 0);
        $this->assertStringContainsString($deletion->public_id, $this->lastReply()->text);
        $this->assertStringContainsString('در حال رسیدگی', $this->lastReply()->text);
        Queue::assertPushed(AnswerTelegramCallback::class);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->pressButton($confirm)->assertOk();
        foreach (['/new After', '/start', '/brand', '/support help', '/ticket x', '/delete_data', 'free text'] as $text) {
            $this->send($text)->assertOk();
            $this->assertStringContainsString($deletion->public_id, $this->lastReply()->text);
            $this->assertSame([], $this->lastReply()->buttons);
        }
        $this->pressButton('onboard:edit')->assertOk();
        $this->assertStringContainsString($deletion->public_id, $this->lastReply()->text);

        $this->assertDatabaseCount('account_deletions', 1);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('credit_ledger', 1);

        $this->expectExceptionObject(new DomainException('ACCOUNT_DELETION_PENDING'));
        app(IssueTelegramTokenAction::class)->execute(new TelegramUserData(555), 'init-data-fixture', 'auth-key-2');
    }

    public function test_forged_expired_or_foreign_confirmations_are_rejected(): void
    {
        $this->onboard(555);
        $other = $this->onboard(556);
        $confirm = $this->confirmationButton(555);

        $this->pressButton(substr($confirm, 0, -1).(str_ends_with($confirm, '0') ? '1' : '0'))->assertOk();
        $this->assertStringContainsString('معتبر یا تازه نیست', $this->lastReply()->text);
        $this->pressButton($confirm, 556)->assertOk();
        $this->assertStringContainsString('معتبر یا تازه نیست', $this->lastReply()->text);
        $this->travel(11)->minutes();
        $this->pressButton($confirm)->assertOk();
        $this->assertStringContainsString('معتبر یا تازه نیست', $this->lastReply()->text);
        $this->assertDatabaseCount('account_deletions', 0);

        $this->pressButton($this->confirmationButton(555))->assertOk();
        $this->assertDatabaseCount('account_deletions', 1);
        $this->assertSame('active', $other->fresh()->status);
        $this->send('/new Other tenant', 556)->assertOk();
        $this->assertPrivateValue(Project::class, ['workspace_id' => $other->id], 'name', 'Other tenant');
    }

    public function test_unknown_telegram_user_gets_no_account_from_delete_request(): void
    {
        $this->send('/delete_data', 777)->assertOk();
        $this->assertStringContainsString('ثبت نشده', $this->lastReply()->text);
        $this->assertSame([], $this->lastReply()->buttons);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_mutations_authorized_before_t0_are_rejected_after_commit(): void
    {
        $workspace = $this->onboard();
        $user = User::query()->sole();
        $stale = Workspace::query()->findOrFail($workspace->id);
        [$first, $replayed] = app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'telegram');
        $this->assertFalse($replayed);
        [$again, $replayed] = app(RequestAccountDeletionAction::class)->execute($workspace->fresh(), $user, 'telegram');
        $this->assertTrue($replayed);
        $this->assertSame($first->public_id, $again->public_id);
        $this->assertSame('active', $stale->status);

        foreach ([
            fn () => app(CreateApiProjectAction::class)->execute($stale, $user->id, 'late-project', 'Late'),
            fn () => app(UpdateBrandProfileAction::class)->execute($stale, ['tone' => 'late'], $user->id),
            fn () => app(AdvanceTelegramOnboardingAction::class)->execute($stale, $user, 'edit'),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Mutation after T0 was accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('ACCOUNT_DELETION_PENDING', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('api_idempotency_records', 0);
        $this->assertNotSame('late', Brand::query()->where('workspace_id', $workspace->id)->first()?->tone);
    }

    public function test_only_the_workspace_owner_can_request_deletion(): void
    {
        $workspace = $this->onboard(555);
        $this->onboard(556);
        $intruder = User::query()->latest('id')->firstOrFail();

        $this->expectException(AuthorizationException::class);
        try {
            app(RequestAccountDeletionAction::class)->execute($workspace, $intruder, 'telegram');
        } finally {
            $this->assertDatabaseCount('account_deletions', 0);
            $this->assertSame('active', $workspace->fresh()->status);
        }
    }

    public function test_contained_workspace_without_receipt_answers_without_retry_loop(): void
    {
        $workspace = $this->onboard();
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);

        $this->send('/start')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertStringContainsString('فعال نیست', $this->lastReply()->text);
        $this->pressButton('onboard:edit')->assertOk();
        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_deterministic_refusal_is_acknowledged_instead_of_retried(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(555));
        $user->forceFill(['status' => 'suspended'])->save();

        $this->send('/start')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseCount('conversation_events', 0);
    }

    public function test_unknown_inline_and_foreign_callbacks_are_acknowledged_and_ignored(): void
    {
        $this->onboard();
        foreach ([
            ['id' => 'cb-unknown', 'from' => ['id' => 555], 'message' => ['chat' => ['id' => 555, 'type' => 'private']], 'data' => 'legacy:button'],
            ['id' => 'cb-inline', 'from' => ['id' => 555], 'inline_message_id' => 'x', 'data' => 'delete:cancel'],
            ['id' => 'cb-game', 'from' => ['id' => 555], 'message' => ['chat' => ['id' => 555, 'type' => 'private']]],
            ['id' => 'cb-group', 'from' => ['id' => 555], 'message' => ['chat' => ['id' => -100, 'type' => 'group']], 'data' => 'onboard:edit'],
        ] as $i => $callback) {
            $this->postJson('/api/webhooks/telegram', ['update_id' => 900 + $i, 'callback_query' => $callback], [
                'X-Telegram-Bot-Api-Secret-Token' => self::SECRET,
            ])->assertOk()->assertJsonPath('data.status', 'ok');
        }
        Queue::assertPushed(AnswerTelegramCallback::class, 4);
        Queue::assertNotPushed(SendTelegramMessage::class);
    }

    public function test_api_routes_are_closed_for_a_contained_workspace(): void
    {
        $workspace = $this->onboard();
        $user = User::query()->sole();
        app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'telegram');
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/brands/current')->assertForbidden();
        $this->postJson('/api/v1/projects', ['name' => 'X'], ['Idempotency-Key' => 'contained-1'])->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
    }
}
