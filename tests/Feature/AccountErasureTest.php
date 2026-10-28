<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Privacy\ProcessAccountDeletionsAction;
use App\Actions\Privacy\PurgeAccountDataAction;
use App\Actions\Privacy\RequestAccountDeletionAction;
use App\Enums\AccountDeletionStatus;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AccountDeletion;
use App\Models\Brand;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Crypto\WorkspaceFieldCipher;
use App\Services\Crypto\WorkspaceKeyManager;
use App\Services\Telegram\TelegramSubjectKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\InMemoryWorkspaceKeyManager;
use Tests\TestCase;

/** FR-087 live purge and NFR-12 deadline handling (ADR-014). */
final class AccountErasureTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-erasure-secret';

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
    }

    private function send(string $text, int $telegramId): TestResponse
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

    /** A seller with brand, memory, project, support ticket, ledger, replay snapshot and queued replies. */
    private function seller(int $telegramId): array
    {
        $this->send('/start', $telegramId)->assertOk();
        $user = app(UserRepositoryInterface::class)->findByIdentity('telegram', (string) $telegramId);
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $this->grantPilotConsent($user);
        $this->actingAs($user, 'sanctum');
        $this->patchJson('/api/v1/brands/current', ['expected_version' => 1, 'name' => "Brand {$telegramId}"], ['Idempotency-Key' => "erasure-brand-{$telegramId}"])->assertOk();
        $this->postJson('/api/v1/projects', ['name' => "Project {$telegramId}"], ['Idempotency-Key' => "erasure-project-{$telegramId}"])->assertCreated();
        $this->send("/support help {$telegramId}", $telegramId)->assertOk();
        $user->createToken('mini-app');
        $this->app->forgetScopedInstances();

        return [$user, $workspace];
    }

    private function requestDeletion(User $user, Workspace $workspace): AccountDeletion
    {
        return app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'telegram')[0];
    }

    private function process(): array
    {
        return app(ProcessAccountDeletionsAction::class)->execute(now()->toImmutable());
    }

    private function keys(): InMemoryWorkspaceKeyManager
    {
        return $this->app->make(WorkspaceKeyManager::class);
    }

    /** @return list<string> chat IDs of queued and failed outbound messages */
    private function outboxChats(): array
    {
        return DB::table('jobs')->pluck('payload')->merge(DB::table('failed_jobs')->pluck('payload'))
            ->map(fn (string $payload) => json_decode($payload, true)['data'])
            ->filter(fn (array $data) => $data['commandName'] === SendTelegramMessage::class)
            ->map(fn (array $data) => (string) unserialize(Crypt::decrypt($data['command']))->chatId)
            ->values()->all();
    }

    /** Every table with a column naming the purged ids must hold no row for them. */
    private function assertNoRowsFor(int $userId, int $workspaceId): void
    {
        $columns = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'owner_id' => $userId, 'confirmed_by' => $userId];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, ['account_deletions', 'migrations'], true)) {
                continue;
            }
            foreach ($columns as $column => $id) {
                if (Schema::hasColumn($table, $column)) {
                    $this->assertSame(0, DB::table($table)->where($column, $id)->count(), "{$table}.{$column} kept a row of the purged account.");
                }
            }
        }
        $this->assertSame(0, DB::table('users')->where('id', $userId)->count());
        $this->assertSame(0, DB::table('workspaces')->where('id', $workspaceId)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $userId)->count());
    }

    public function test_purge_removes_every_live_row_key_and_message_of_one_account_only(): void
    {
        [$user, $workspace] = $this->seller(555);
        [$other, $otherWorkspace] = $this->seller(777);
        $keyName = app(WorkspaceFieldCipher::class)->keyName($workspace);
        $wrapped = DB::table('workspace_data_keys')->where('workspace_id', $workspace->id)->value('wrapped_key');
        $this->assertContains('555', $this->outboxChats());
        $subjects = app(TelegramSubjectKey::class);
        $this->assertTrue(DB::table('conversation_events')->where('subject_key', $subjects->for(555))->exists());

        $deletion = $this->requestDeletion($user, $workspace);
        $this->assertSame(['purged' => 1, 'failed' => 0, 'completed' => 0, 'at_risk' => 0], $this->process());

        $this->assertNoRowsFor($user->id, $workspace->id);
        $this->assertNotContains('555', $this->outboxChats());
        $this->assertFalse(DB::table('conversation_events')->where('subject_key', $subjects->for(555))->exists());
        $this->assertNull(app(UserRepositoryInterface::class)->findByIdentity('telegram', '555'));
        try {
            $this->keys()->unwrapDataKey($keyName, $wrapped);
            $this->fail('The purged workspace key still unwraps.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $receipt = $deletion->fresh();
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $receipt->status);
        $this->assertNull($receipt->workspace_id);
        $this->assertNull($receipt->user_id);
        $this->assertSame($keyName, $receipt->transit_key);
        $this->assertNotNull($receipt->key_destroyed_at);
        $this->assertNotNull($receipt->live_purged_at);
        $this->assertTrue($receipt->deadline_at->equalTo($deletion->deadline_at));

        // The other tenant keeps its data, messages, events and readable key.
        $this->assertContains('777', $this->outboxChats());
        $this->assertTrue(DB::table('conversation_events')->where('subject_key', $subjects->for(777))->exists());
        $this->app->forgetScopedInstances();
        $this->assertSame('Brand 777', Brand::query()->where('workspace_id', $otherWorkspace->id)->sole()->name);
        $this->assertSame('active', $otherWorkspace->fresh()->status);
        $this->assertSame(1, DB::table('user_identities')->where('user_id', $other->id)->count());
    }

    public function test_repeated_passes_do_not_purge_twice_or_move_t0(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        $this->process();
        $first = $deletion->fresh();

        $this->travel(2)->hours();
        $this->assertSame(['purged' => 0, 'failed' => 0, 'completed' => 0, 'at_risk' => 0], $this->process());
        $again = app(PurgeAccountDataAction::class)->execute($first);

        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $again->status);
        $this->assertTrue($again->live_purged_at->equalTo($first->live_purged_at));
        $this->assertTrue($again->requested_at->equalTo($deletion->requested_at));
        $this->assertSame(0, $again->attempts);
    }

    public function test_key_service_outage_keeps_request_pending_and_data_until_retry(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        $this->keys()->failDestroy = true;
        Log::spy();

        $this->artisan('privacy:process-deletions')->assertFailed();

        $pending = $deletion->fresh();
        $this->assertSame(AccountDeletionStatus::Requested, $pending->status);
        $this->assertSame(1, $pending->attempts);
        $this->assertSame(RuntimeException::class, $pending->last_error);
        $this->assertNull($pending->key_destroyed_at);
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->count());
        Log::shouldHaveReceived('error')->with('privacy.purge_failed', ['receipt' => $deletion->public_id, 'exception_type' => RuntimeException::class])->once();

        $this->keys()->failDestroy = false;
        $this->artisan('privacy:process-deletions')->assertSuccessful();
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $deletion->fresh()->status);
        $this->assertNull($deletion->fresh()->last_error);
        $this->assertNoRowsFor($user->id, $workspace->id);
    }

    /** Data must still decrypt: a failed precondition must not have destroyed the key first. */
    private function assertKeyIntact(Workspace $workspace): void
    {
        $this->app->forgetScopedInstances();
        $this->assertSame("Brand {$workspace->owner->identities()->value('external_id')}", Brand::query()->where('workspace_id', $workspace->id)->sole()->name);
    }

    public function test_active_workspace_is_never_purged_and_keeps_its_key(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        // Operator error or bad restore: the receipt exists but containment was lifted.
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'active']);

        try {
            app(PurgeAccountDataAction::class)->execute($deletion);
            $this->fail('An active workspace was purged.');
        } catch (LogicException $exception) {
            $this->assertSame('Only a contained workspace can be purged.', $exception->getMessage());
        }
        $this->assertKeyIntact($workspace);
        $this->assertNull($deletion->fresh()->key_destroy_started_at);
        $this->assertSame(AccountDeletionStatus::Requested, $deletion->fresh()->status);
    }

    public function test_account_with_another_member_or_workspace_keeps_its_key(): void
    {
        [$user, $workspace] = $this->seller(555);
        [$other] = $this->seller(777);
        $deletion = $this->requestDeletion($user, $workspace);
        $workspace->members()->attach($other->id, ['role' => 'member']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('single-owner');
        try {
            app(PurgeAccountDataAction::class)->execute($deletion);
        } finally {
            $this->assertKeyIntact($workspace);
            $this->assertSame(1, DB::table('workspace_memberships')->where('user_id', $other->id)->where('workspace_id', '!=', $workspace->id)->count());
        }
    }

    public function test_missing_transit_key_on_first_try_is_not_counted_as_destroyed(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        // Stored data key points at a name the key service does not know (wrong prefix or mount).
        DB::table('workspace_data_keys')->where('workspace_id', $workspace->id)->update(['transit_key' => 'ws-renamed-elsewhere']);

        $this->assertSame(1, $this->process()['failed']);

        $pending = $deletion->fresh();
        $this->assertSame('ws-renamed-elsewhere', $pending->transit_key);
        $this->assertNull($pending->key_destroyed_at);
        $this->assertSame(LogicException::class, $pending->last_error);
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->count());
    }

    public function test_destroy_retry_after_a_crash_accepts_the_missing_key(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        $name = app(WorkspaceFieldCipher::class)->keyName($workspace);
        // Crash after the destroy call but before it was recorded.
        $deletion->forceFill(['transit_key' => $name, 'key_destroy_started_at' => now()])->save();
        $this->keys()->destroy($name);

        $this->assertSame(1, $this->process()['purged']);
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $deletion->fresh()->status);
    }

    public function test_completion_needs_evidenced_backup_lifecycle_and_both_expiry_windows(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        $this->process();

        // Without Ops evidence the receipt never completes (FR-088).
        $this->travel(10)->days();
        $this->process();
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $deletion->fresh()->status);

        config(['karmandeman.erasure.backup_lifecycle_verified' => true]);
        $this->travelBack();
        $this->travelTo($deletion->fresh()->live_purged_at->addHours(143));
        $this->process();
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $deletion->fresh()->status);

        $this->travelTo($deletion->fresh()->live_purged_at->addHours(144));
        $this->process();
        $done = $deletion->fresh();
        $this->assertSame(AccountDeletionStatus::Completed, $done->status);
        $this->assertNull($done->transit_key);
        $this->assertTrue($done->completed_at->lessThanOrEqualTo($done->deadline_at));
    }

    public function test_unfinished_requests_alert_before_and_after_the_deadline(): void
    {
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        $this->process();
        Log::spy();

        $this->travelTo($deletion->deadline_at->subHours(25));
        $this->assertSame(0, $this->process()['at_risk']);

        $this->travelTo($deletion->deadline_at->subHours(23));
        $this->assertSame(1, $this->process()['at_risk']);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $event, array $context) => $event === 'privacy.deletion_deadline_at_risk' && $context['receipt'] === $deletion->public_id);

        $this->travelTo($deletion->deadline_at);
        $this->process();
        Log::shouldHaveReceived('critical')->withArgs(fn (string $event, array $context) => $event === 'privacy.deletion_deadline_breached' && $context['status'] === 'awaiting_backup_expiry');
    }

    public function test_backup_cap_that_outlives_the_deadline_is_flagged(): void
    {
        config(['karmandeman.erasure.database_backup_max_age_hours' => 168]);
        [$user, $workspace] = $this->seller(555);
        $deletion = $this->requestDeletion($user, $workspace);
        Log::spy();

        $this->travel(1)->minutes();
        $this->process();

        Log::shouldHaveReceived('critical')->with('privacy.backup_window_exceeds_deadline', ['receipt' => $deletion->public_id]);
    }

    public function test_rollback_refuses_while_detached_receipts_exist(): void
    {
        [$user, $workspace] = $this->seller(555);
        $this->requestDeletion($user, $workspace);
        $this->process();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Detached erasure receipts exist');
        (require database_path('migrations/2026_10_28_000001_track_account_erasure.php'))->down();
    }
}
