<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\UpdateBrandProfileAction;
use App\Actions\Identity\RegisterTelegramUserAction;
use App\Actions\Wallet\GrantInitialCreditsAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\TelegramUserData;
use App\Enums\CreditLedgerType;
use App\Models\User;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\Telegram\TelegramReplyService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

final class FoundationSecurityTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    public function test_api_without_accept_header_still_returns_json_unauthorized(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_non_owner_and_suspended_workspace_cannot_read_or_write(): void
    {
        $owner = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($owner);
        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member']);
        Sanctum::actingAs($member);

        foreach (['brands/current', 'projects', 'wallet/ledger', 'workspaces/current'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        }
        $this->patchJson('/api/v1/brands/current', ['name' => 'unauthorized'])->assertForbidden();
        $this->postJson('/api/v1/projects')->assertForbidden();
        $this->assertDatabaseCount('brands', 0);
        $this->assertDatabaseCount('projects', 0);

        $workspace->update(['status' => 'suspended']);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/workspaces/current')->assertForbidden();
    }

    public function test_suspended_account_is_blocked_even_with_a_valid_token(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'suspended']));
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_api_envelope_handles_auth_validation_and_pagination(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized()->assertJsonStructure(['error' => ['code'], 'meta' => ['request_id', 'api_version']]);
        $this->useApprovedPilotPolicy();
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(987));
        $this->grantPilotConsent($user);
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/brands/current', ['currency_display_unit' => 'bad'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details']]);
        for ($i = 0; $i < 21; $i++) {
            $this->postJson('/api/v1/projects', ['name' => "P{$i}"], ['Idempotency-Key' => "page-project-{$i}"])->assertCreated();
        }
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)->assertJsonPath('meta.api_version', 'v1')->assertJsonStructure(['links' => ['next']]);
        $this->getJson('/api/v1/projects?page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me')->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_brand_and_memory_roll_back_together(): void
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($user);
        $brands = app(BrandRepositoryInterface::class);
        $brand = $brands->ensureFor($workspace);
        $memory = Mockery::mock(MemoryItemRepositoryInterface::class);
        $memory->shouldReceive('put')->once()->andThrow(new RuntimeException('simulated storage failure'));
        $action = new UpdateBrandProfileAction($brands, $memory, app(LockActiveWorkspaceAction::class));
        try {
            $action->execute($workspace, ['name' => 'should roll back'], $user->id);
            $this->fail('Expected the memory failure.');
        } catch (RuntimeException) {
            $this->assertNull($brand->fresh()->name);
            $this->assertSame(1, $brand->fresh()->profile_version);
            $this->assertDatabaseCount('memory_items', 0);
        }
    }

    public function test_ledger_rejects_key_reuse_across_wallets_and_changed_amount(): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $workspaces = app(WorkspaceRepositoryInterface::class);
        $a = $wallets->ensureWallet($workspaces->createForOwner(User::factory()->create()));
        $b = $wallets->ensureWallet($workspaces->createForOwner(User::factory()->create()));
        $wallets->appendEntry($a, CreditLedgerType::Grant, 100, 'same-key');
        foreach ([[$b, 100], [$a, 200]] as [$wallet, $amount]) {
            try {
                $wallets->appendEntry($wallet, CreditLedgerType::Grant, $amount, 'same-key');
                $this->fail('Expected idempotency conflict.');
            } catch (DomainException $exception) {
                $this->assertSame('IDEMPOTENCY_KEY_REUSED', $exception->getMessage());
            }
        }
        $this->assertSame(100, $a->fresh()->balance_cached);
        $this->assertSame(0, $b->fresh()->balance_cached);
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_unimplemented_ledger_operations_cannot_silently_change_balance(): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $wallet = $wallets->ensureWallet(app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create()));
        foreach ([[CreditLedgerType::Grant, -1], [CreditLedgerType::Grant, 0], [CreditLedgerType::Reserve, -10], [CreditLedgerType::Capture, -10], [CreditLedgerType::Purchase, 10]] as [$type, $amount]) {
            try {
                $wallets->appendEntry($wallet, $type, $amount, 'invalid-'.$type->value.$amount);
                $this->fail('Unsupported ledger write accepted.');
            } catch (DomainException $exception) {
                $this->assertSame('UNSUPPORTED_LEDGER_OPERATION', $exception->getMessage());
            }
        }
        $this->assertSame(0, $wallet->fresh()->balance_cached);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_gift_is_not_regranted_after_configuration_changes(): void
    {
        config(['karmandeman.gift_credits' => 100]);
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner(User::factory()->create());
        app(GrantInitialCreditsAction::class)->execute($workspace);
        config(['karmandeman.gift_credits' => 500]);
        app(GrantInitialCreditsAction::class)->execute($workspace);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame(100, app(WalletRepositoryInterface::class)->forWorkspace($workspace)->balance_cached);
    }

    public function test_database_outbox_rolls_back_with_domain_state(): void
    {
        DB::beginTransaction();
        app(TelegramReplyService::class)->sendMessage(999, 'sensitive-test-content');
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('sensitive-test-content', $payload);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_wallet_and_brand_reads_are_isolated_from_another_owner(): void
    {
        config(['karmandeman.gift_credits' => 100]);
        $userA = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(991));
        config(['karmandeman.gift_credits' => 20]);
        $userB = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(992));
        $this->useApprovedPilotPolicy();
        $this->grantPilotConsent($userA);
        Sanctum::actingAs($userA);
        $this->patchJson('/api/v1/brands/current', ['name' => 'Private A', 'expected_version' => 1], ['Idempotency-Key' => 'private-brand-1'])->assertOk();
        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/brands/current?workspace_id='.$userA->workspaces()->first()->id)
            ->assertOk()->assertJsonPath('data.name', null);
        $this->getJson('/api/v1/wallet/ledger')->assertOk()
            ->assertJsonPath('data.balance', 20)->assertJsonPath('data.entries.0.amount', 20)->assertJsonCount(1, 'data.entries');
    }

    public function test_brand_rejects_nested_objects_and_unbounded_preferences(): void
    {
        Sanctum::actingAs(app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(993)));
        $this->patchJson('/api/v1/brands/current', [
            'color_palette' => [['script' => 'bad']],
            'default_outputs' => ['unapproved-output'],
            'contact_channels' => [['nested' => true]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('memory_items', 0);
    }

    public function test_zero_gift_registration_still_provisions_an_empty_wallet(): void
    {
        config(['karmandeman.gift_credits' => 0]);
        Sanctum::actingAs(app(RegisterTelegramUserAction::class)->execute(new TelegramUserData(994)));
        $this->getJson('/api/v1/wallet/ledger')->assertOk()->assertJsonPath('data.balance', 0)->assertJsonCount(0, 'data.entries');
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
