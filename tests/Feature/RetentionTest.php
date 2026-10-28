<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Privacy\PruneRetentionAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\Brand;
use App\Models\TelegramOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** FR-084 retention limits and Telegram identity minimisation (ADR-015). */
final class RetentionTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-retention-secret';

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config(['services.telegram.webhook_secret' => self::SECRET, 'services.telegram.bot_token' => '123:test-token']);
    }

    private function send(string $text, int $telegramId = 555): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', [
            'update_id' => ++$this->updateId,
            'message' => [
                'message_id' => $this->updateId, 'date' => time(), 'text' => $text,
                'from' => ['id' => $telegramId, 'first_name' => 'مریم', 'last_name' => 'Rahimi', 'username' => 'maryam_shop', 'language_code' => 'fa'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    private function prune(): array
    {
        return app(PruneRetentionAction::class)->execute(now()->toImmutable());
    }

    /** @return array{User, Workspace} */
    private function account(int $telegramId = 555): array
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($telegramId, 'مریم', null, 'maryam_shop', 'fa'));

        return [$user, app(WorkspaceRepositoryInterface::class)->currentFor($user)];
    }

    public function test_registration_keeps_only_the_telegram_id(): void
    {
        $this->send('/start')->assertOk();

        $user = User::query()->sole();
        $this->assertNull($user->getRawOriginal('name'));
        $identity = DB::table('user_identities')->where('user_id', $user->id)->sole();
        $this->assertSame('555', $identity->external_id);
        $this->assertNull($identity->meta);
        $this->assertNull(DB::table('workspaces')->value('name'));
        $this->assertSame([], array_filter(DB::table('users')->pluck('name')->all()));
    }

    public function test_migration_erases_stored_names_and_usernames(): void
    {
        $user = User::query()->create(['status' => 'active']);
        DB::table('users')->where('id', $user->id)->update(['name' => 'Legacy Name']);
        DB::table('user_identities')->insert([
            'user_id' => $user->id, 'provider' => 'telegram', 'external_id' => '901', 'verified_at' => now(),
            'meta' => json_encode(['username' => 'legacy_user']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_10_28_000002_enforce_retention_and_minimise_identity.php'))->up();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('name'));
        $this->assertNull(DB::table('user_identities')->where('external_id', '901')->value('meta'));
        $this->assertSame(1, DB::table('user_identities')->where('external_id', '901')->count());
    }

    public function test_conversation_events_expire_after_seven_days(): void
    {
        $this->send('/start')->assertOk();
        $this->travel(167)->hours();
        $this->send('/balance')->assertOk();
        $this->assertSame(0, $this->prune()['conversation_events']);

        $this->travel(2)->hours();
        $this->assertSame(1, $this->prune()['conversation_events']);
        $this->assertSame(1, DB::table('conversation_events')->count());
    }

    public function test_outbox_messages_expire_by_original_creation_even_after_retries(): void
    {
        $replies = app(TelegramReplyService::class);
        $replies->sendMessage(111, 'queued long ago');
        // A release re-inserts the row with a fresh created_at; the payload keeps createdAt.
        DB::table('jobs')->update(['created_at' => now()->addHours(30)->getTimestamp()]);
        $failed = DB::table('jobs')->sole();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'telegram', 'queue' => 'telegram',
            'payload' => $failed->payload, 'exception' => 'RuntimeException', 'failed_at' => now()->addMinutes(10),
        ]);

        $this->travel(23)->hours();
        $replies->sendMessage(222, 'recent');
        $this->assertSame(0, $this->prune()['telegram_outbox']);

        $this->travel(2)->hours();
        $this->assertSame(1, $this->prune()['telegram_outbox']);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->travel(144)->hours();
        $this->assertSame(2, $this->prune()['telegram_outbox']);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_auth_replay_expires_an_hour_after_the_token_or_on_revocation(): void
    {
        [$user] = $this->account();
        [$revoked] = $this->account(556);
        $records = app(TelegramAuthRequestRepositoryInterface::class);
        $token = $user->createToken('telegram-webapp', ['*'], now()->addHours(8));
        $records->record($user, 'replay-key-0001', 'hash', 'cipher', $token->accessToken->id);
        $gone = $revoked->createToken('telegram-webapp', ['*'], now()->addHours(8));
        $records->record($revoked, 'replay-key-0002', 'hash', 'cipher', $gone->accessToken->id);
        $revoked->tokens()->delete();

        $this->assertSame(1, $this->prune()['auth_replays']);
        $this->assertSame(1, DB::table('telegram_auth_requests')->where('user_id', $user->id)->count());

        $this->travel(9 * 3600 + 1)->seconds();
        $this->assertSame(1, $this->prune()['auth_replays']);
        $this->assertSame(0, DB::table('telegram_auth_requests')->count());
    }

    public function test_expired_api_snapshot_never_runs_the_mutation_again(): void
    {
        [$user] = $this->account();
        $this->grantPilotConsent($user);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/v1/projects', ['name' => 'Retained'], ['Idempotency-Key' => 'retention-project-1'])->assertCreated();
        $this->app->forgetScopedInstances();
        $this->postJson('/api/v1/projects', ['name' => 'Retained'], ['Idempotency-Key' => 'retention-project-1'])->assertOk();

        $this->travel(169)->hours();
        $this->assertSame(1, $this->prune()['api_snapshots']);
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/projects', ['name' => 'Retained'], ['Idempotency-Key' => 'retention-project-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_EXPIRED');
        $this->assertSame(1, DB::table('projects')->count());
        $this->assertSame(1, DB::table('api_idempotency_records')->count());
        // A different payload under the spent key is still a conflict, not a new run.
        $this->postJson('/api/v1/projects', ['name' => 'Other'], ['Idempotency-Key' => 'retention-project-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->postJson('/api/v1/projects', ['name' => 'Fresh'], ['Idempotency-Key' => 'retention-project-2'])->assertCreated();
    }

    public function test_abandoned_onboarding_draft_is_cleared_and_confirmed_brand_stays_ready(): void
    {
        [$user, $workspace] = $this->account();
        [$editor, $edited] = $this->account(556);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        foreach ([[$user, $workspace], [$editor, $edited]] as [$owner, $space]) {
            $onboarding->execute($space, $owner, 'accept', $this->policyConsentToken());
            $onboarding->execute($space, $owner, 'input', 'Draft brand');
            $onboarding->execute($space, $owner, 'unit', 'toman');
        }
        // The second seller already has a confirmed brand and is editing it.
        foreach (['skip', 'skip', 'confirm', 'edit'] as $event) {
            $onboarding->execute($edited, $editor, $event);
        }
        $onboarding->execute($edited, $editor, 'input', 'Renamed');

        $this->travel(167)->hours();
        $this->assertSame(0, $this->prune()['onboarding_drafts']);
        $this->travel(2)->hours();
        $this->assertSame(2, $this->prune()['onboarding_drafts']);

        $fresh = TelegramOnboarding::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('name', $fresh->step);
        $this->assertNull($fresh->getRawOriginal('draft'));
        $ready = TelegramOnboarding::query()->where('workspace_id', $edited->id)->sole();
        $this->assertSame('ready', $ready->step);
        $this->assertNull($ready->getRawOriginal('draft'));
        $this->assertTrue($onboarding->isReady($edited));
        $this->app->forgetScopedInstances();
        $this->assertSame('Draft brand', Brand::query()->where('workspace_id', $edited->id)->sole()->name);
    }
}
