<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Brand\UpdateApiBrandAction;
use App\Actions\Brand\UpdateBrandProfileAction;
use App\Actions\Identity\HandleTelegramUpdateAction;
use App\Actions\Identity\IssueTelegramTokenAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Privacy\PurgeAccountDataAction;
use App\Actions\Privacy\RequestAccountDeletionAction;
use App\Actions\Product\ApproveProductRevisionAction;
use App\Actions\Product\CreateManualProductDraftAction;
use App\Actions\Product\ReviseProductDraftAction;
use App\Actions\Project\ApproveProjectProductsAction;
use App\Actions\Project\CreateApiProjectAction;
use App\Actions\Project\FinishProjectInputAction;
use App\Data\Telegram\TelegramUserData;
use App\Enums\AccountDeletionStatus;
use App\Enums\CreditLedgerType;
use App\Models\User;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;
use Throwable;

final class FoundationConcurrencyTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
    }

    protected function connectionsToTransact(): array
    {
        // Children must see committed fixtures and real row locks.
        return [];
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires disposable PostgreSQL and pcntl. SQLite cannot verify row locking.');
        }
        RefreshDatabaseState::$migrated = false;
    }

    private function concurrently(callable $operation, int $count = 6): array
    {
        DB::disconnect();
        $workers = [];
        for ($i = 0; $i < $count; $i++) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork concurrency worker.');
            }
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                try {
                    DB::purge();
                    fwrite($child, json_encode(['result' => $operation($i)]));
                    fclose($child);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($child, json_encode(['error' => $exception::class, 'message' => $exception->getMessage()]));
                    fclose($child);
                    exit(1);
                }
            }
            fclose($child);
            stream_set_timeout($parent, 20);
            $workers[] = [$pid, $parent];
        }
        foreach ($workers as [, $socket]) {
            fwrite($socket, 'x');
        }
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $result = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), $result);
            $results[] = json_decode($result, true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        DB::purge();

        return $results;
    }

    public function test_parallel_first_registration_provisions_exactly_one_account_and_gift(): void
    {
        config(['karmandeman.gift_credits' => 100]);
        $ids = $this->concurrently(fn () => app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(789))->id);
        $this->assertCount(1, array_unique($ids));
        foreach (['users', 'user_identities', 'workspaces', 'workspace_memberships', 'employees', 'wallets', 'credit_ledger'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame(100, (int) DB::table('wallets')->value('balance_cached'));
    }

    public function test_parallel_grant_retries_return_one_entry_and_one_balance_increment(): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $wallet = $wallets->ensureWallet(app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create()));
        $ids = $this->concurrently(fn () => app(WalletRepositoryInterface::class)->appendEntry($wallet, CreditLedgerType::Grant, 100, 'parallel-grant')->id);
        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame(100, $wallet->fresh()->balance_cached);
    }

    public function test_parallel_duplicate_webhooks_commit_one_project_and_outbox_message(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(789));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'skip');
        $onboarding->execute($workspace, $user, 'confirm');
        $update = ['update_id' => 1, 'message' => [
            'text' => '/new concurrent', 'from' => ['id' => 789], 'chat' => ['id' => 789, 'type' => 'private'],
        ]];
        $statuses = $this->concurrently(fn () => app(HandleTelegramUpdateAction::class)->execute($update));
        $this->assertSame(1, count(array_filter($statuses, fn ($status) => $status === 'ok')));
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('conversation_events', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_parallel_brand_updates_preserve_version_and_memory_revision(): void
    {
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create());
        app(BrandRepositoryInterface::class)->ensureFor($workspace);
        $this->concurrently(fn ($i) => app(UpdateBrandProfileAction::class)->execute($workspace, ['name' => "Brand {$i}"])->id);
        $this->assertSame(7, app(BrandRepositoryInterface::class)->forWorkspace($workspace)->profile_version);
        $this->assertSame(6, (int) DB::table('memory_items')->value('revision'));
        $this->assertDatabaseCount('memory_items', 1);
    }

    public function test_parallel_api_project_retries_create_one_project(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(91234));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $this->grantPilotConsent($user);
        $ids = $this->concurrently(fn () => app(CreateApiProjectAction::class)
            ->execute($workspace, $user->id, 'parallel-project-key', 'Batch')[0]->id);
        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('api_idempotency_records', 1);
    }

    public function test_parallel_api_brand_updates_reject_stale_version(): void
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($user);
        $this->grantPilotConsent($user);
        $results = $this->concurrently(function (int $i) use ($workspace, $user): string {
            try {
                app(UpdateApiBrandAction::class)->execute($workspace, $user->id, "brand-parallel-{$i}", 1, ['name' => "Brand {$i}"]);

                return 'updated';
            } catch (\DomainException $e) {
                return $e->getMessage();
            }
        });
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'updated')));
        $this->assertSame(5, count(array_filter($results, fn ($result) => $result === 'BRAND_VERSION_CONFLICT')));
        $this->assertSame(2, app(BrandRepositoryInterface::class)->forWorkspace($workspace)->profile_version);
        $this->assertDatabaseCount('api_idempotency_records', 1);
    }

    public function test_parallel_product_approvals_and_revisions_commit_once(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(91240));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $this->grantPilotConsent($user);
        $project = app(CreateApiProjectAction::class)->execute($workspace, $user->id, 'product-batch-key', 'Batch')[0];
        $draft = ['name' => ['value' => 'Bag', 'status' => 'confirmed']];
        $product = app(CreateManualProductDraftAction::class)
            ->execute($workspace, $user, 'product-create-key', $project->public_id, $draft)[0]->product->public_id;

        // Six clicks with distinct keys: one approval stamp, the rest see it already approved.
        $stamps = $this->concurrently(fn (int $i) => app(ApproveProductRevisionAction::class)
            ->execute($workspace, $user, "approve-click-{$i}", $product, 1)[0]->approved_at?->toIso8601String());
        $this->assertCount(1, array_unique($stamps));
        $this->assertSame(1, DB::table('product_revisions')->whereNotNull('approved_at')->count());

        $results = $this->concurrently(function (int $i) use ($workspace, $user, $product, $draft): string {
            try {
                app(ReviseProductDraftAction::class)->execute($workspace, $user, "revise-race-{$i}", $product, 1, $draft);

                return 'revised';
            } catch (\DomainException $e) {
                return $e->getMessage();
            }
        });
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'revised')));
        $this->assertSame(5, count(array_filter($results, fn ($result) => $result === 'PRODUCT_REVISION_CONFLICT')));
        $this->assertSame(2, DB::table('product_revisions')->count());
        $this->assertSame(2, (int) DB::table('products')->value('current_revision'));
    }

    public function test_parallel_products_with_one_sku_create_once(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(91250));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $this->grantPilotConsent($user);
        $project = app(CreateApiProjectAction::class)->execute($workspace, $user->id, 'sku-batch-key', 'Batch')[0];

        // Same SKU in Latin and Persian digits, distinct keys: one product holds it (ADR-017).
        $results = $this->concurrently(function (int $i) use ($workspace, $user, $project): string {
            try {
                app(CreateManualProductDraftAction::class)->execute($workspace, $user, "sku-race-{$i}", $project->public_id, [
                    'sku' => ['value' => $i % 2 === 0 ? 'ary-1007' : 'ARY-۱۰۰۷', 'status' => 'confirmed'],
                ]);

                return 'created';
            } catch (\DomainException $e) {
                return $e->getMessage();
            }
        });
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'created')));
        $this->assertSame(5, count(array_filter($results, fn ($result) => $result === 'PRODUCT_SKU_TAKEN')));
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_skus', 1);
    }

    public function test_parallel_batch_approvals_freeze_one_snapshot(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(91260));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $this->grantPilotConsent($user);
        $project = app(CreateApiProjectAction::class)->execute($workspace, $user->id, 'snapshot-batch-key', 'Batch')[0];
        $product = app(CreateManualProductDraftAction::class)->execute($workspace, $user, 'snapshot-product-key', $project->public_id, [
            'name' => ['value' => 'Bag', 'status' => 'confirmed'],
        ])[0]->product->public_id;
        app(ApproveProductRevisionAction::class)->execute($workspace, $user, 'snapshot-approve-key', $product, 1);
        app(FinishProjectInputAction::class)->execute($workspace, $user->id, 'snapshot-finish-key', $project->public_id, 3);

        // Six clicks with distinct keys: one snapshot, the rest see the batch already approved (ADR-018).
        $results = $this->concurrently(function (int $i) use ($workspace, $user, $project): string {
            try {
                app(ApproveProjectProductsAction::class)->execute($workspace, $user, "snapshot-race-{$i}", $project->public_id, 4);

                return 'approved';
            } catch (\DomainException $e) {
                return $e->getMessage();
            }
        });
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result === 'approved')));
        $this->assertSame(5, count(array_filter($results, fn ($result) => $result === 'PROJECT_STATE_CONFLICT')));
        $this->assertDatabaseCount('project_snapshots', 1);
        $this->assertSame(5, (int) DB::table('projects')->value('version'));
    }

    public function test_parallel_token_retries_issue_one_token(): void
    {
        app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(92010));
        $tokens = $this->concurrently(fn () => app(IssueTelegramTokenAction::class)
            ->execute(new TelegramUserData(92010), 'verified-init-data-fixture', 'parallel-auth-key')[1]);
        $this->assertCount(1, array_unique($tokens));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('telegram_auth_requests', 1);
    }

    public function test_parallel_brand_confirmation_clicks_commit_one_revision(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(92011));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        $onboarding->execute($workspace, $user, 'input', 'Confirmed Brand');
        $onboarding->execute($workspace, $user, 'unit', 'toman');
        $onboarding->execute($workspace, $user, 'skip');
        $review = $onboarding->execute($workspace, $user, 'skip');
        $token = substr($review->buttons[0][0]['callback_data'], strlen('onboard:confirm:'));

        $results = $this->concurrently(fn () => app(AdvanceTelegramOnboardingAction::class)
            ->execute($workspace, $user, 'confirm', $token)->text);
        $this->assertCount(1, array_filter($results, fn ($text) => str_contains($text, 'شناخت اولیه رایگان است')));
        $this->assertSame(2, app(BrandRepositoryInterface::class)->forWorkspace($workspace)->profile_version);
        $this->assertDatabaseCount('memory_items', 2);
        $this->assertDatabaseCount('memory_item_revisions', 2);
        $this->assertDatabaseCount('workspace_consents', 1);
    }

    public function test_parallel_deletion_confirmations_commit_one_request_and_contain_workspace(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(92012));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $user->createToken('mini-app');

        $ids = $this->concurrently(fn () => app(RequestAccountDeletionAction::class)
            ->execute($workspace, $user, 'telegram')[0]->public_id);
        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('account_deletions', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('deletion_pending', $workspace->fresh()->status);
    }

    public function test_parallel_sign_in_and_deletion_do_not_deadlock(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(92013));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);

        // Sign-in locks the user row first; deletion inserts an FK to it under the workspace lock.
        $results = $this->concurrently(function (int $i) use ($user, $workspace): string {
            try {
                return $i % 2 === 0
                    ? (app(IssueTelegramTokenAction::class)->execute(new TelegramUserData(92013), 'verified-init-data-fixture', "sign-in-key-{$i}")[1] !== '' ? 'token' : 'empty')
                    : 'deletion:'.app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'mini_app')[0]->public_id;
            } catch (\DomainException $exception) {
                return $exception->getMessage();
            }
        }, 8);

        $this->assertCount(1, array_unique(array_filter($results, fn ($result) => str_starts_with($result, 'deletion:'))));
        $this->assertSame([], array_diff($results, ['token', 'ACCOUNT_DELETION_PENDING', ...array_filter($results, fn ($result) => str_starts_with($result, 'deletion:'))]));
        $this->assertDatabaseCount('account_deletions', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame('deletion_pending', $workspace->fresh()->status);
    }

    public function test_parallel_purge_passes_and_messages_purge_once_without_deadlock(): void
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(92077));
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $deletion = app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'telegram')[0];

        // Purge locks user, workspace, receipt; webhook registration locks the user first too.
        $results = $this->concurrently(fn (int $i): string => $i % 2 === 0
            ? app(PurgeAccountDataAction::class)->execute($deletion)->status->value
            : app(HandleTelegramUpdateAction::class)->execute(['update_id' => 500 + $i, 'message' => [
                'message_id' => 500 + $i, 'text' => '/balance', 'from' => ['id' => 92077], 'chat' => ['id' => 92077, 'type' => 'private'],
            ]]), 8);

        $this->assertSame([], array_diff($results, ['awaiting_backup_expiry', 'ok']));
        $this->assertSame(AccountDeletionStatus::AwaitingBackupExpiry, $deletion->fresh()->status);
        $this->assertSame(0, DB::table('users')->where('id', $user->id)->count());
        $this->assertSame(0, DB::table('workspaces')->where('id', $workspace->id)->count());
        // A message after the purge may register a fresh account; never more than one.
        $this->assertLessThanOrEqual(1, DB::table('user_identities')->where('external_id', '92077')->count());
    }

    public function test_outbox_is_invisible_to_other_connections_until_commit(): void
    {
        config(['database.connections.observer' => config('database.connections.pgsql')]);
        DB::beginTransaction();
        app(TelegramReplyService::class)->sendMessage(789, 'test-only');
        $this->assertSame(0, DB::connection('observer')->table('jobs')->count());
        DB::commit();
        $this->assertSame(1, DB::connection('observer')->table('jobs')->count());
        DB::purge('observer');
    }
}
