<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Wallet\CaptureCreditsAction;
use App\Actions\Wallet\GrantInitialCreditsAction;
use App\Actions\Wallet\ReleaseCreditsAction;
use App\Actions\Wallet\ReserveCreditsAction;
use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    public function test_append_entry_is_idempotent_and_balance_stays_consistent(): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $workspace = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);
        $wallet = $wallets->ensureWallet($workspace);

        $first = $wallets->appendEntry($wallet, CreditLedgerType::Grant, 100, 'key-1');
        $second = $wallets->appendEntry($wallet, CreditLedgerType::Grant, 100, 'key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(100, $wallet->fresh()->balance_cached);

        $sum = $wallets->entries($wallet)->sum('amount');
        $this->assertSame($sum, $wallet->fresh()->balance_cached);
    }

    public function test_a_hold_is_settled_once_and_a_release_never_adds_credit(): void
    {
        $wallets = app(WalletRepositoryInterface::class);
        $workspace = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);
        $wallet = $wallets->ensureWallet($workspace);
        $wallets->appendEntry($wallet, CreditLedgerType::Grant, 10, 'grant-10');
        $reserve = fn (int $amount, string $key, int $related) => DB::transaction(fn () => app(ReserveCreditsAction::class)->execute($workspace, $amount, $key, 'ai_workflow_run', $related, 'test'));

        $held = $reserve(6, 'hold-a', 1);
        $this->assertSame($held->id, $reserve(6, 'hold-a', 1)->id);
        $this->assertSame([10, 6], [$wallet->fresh()->balance_cached, $wallet->fresh()->reserved_cached]);
        // Only the free credit can be held, and a key never changes what it held.
        $this->assertDomainError('INSUFFICIENT_CREDITS', fn () => $reserve(5, 'hold-b', 2));
        $this->assertDomainError('IDEMPOTENCY_KEY_REUSED', fn () => $reserve(4, 'hold-a', 1));
        $this->assertDomainError('UNSUPPORTED_LEDGER_OPERATION', fn () => $reserve(0, 'hold-c', 3));

        $released = DB::transaction(fn () => app(ReleaseCreditsAction::class)->execute($held, 'failed'));
        // A capture after the release, and a second release, return the first settlement.
        $this->assertSame($released->id, DB::transaction(fn () => app(CaptureCreditsAction::class)->execute($held, 'late')->id));
        $this->assertSame($released->id, DB::transaction(fn () => app(ReleaseCreditsAction::class)->execute($held, 'again')->id));
        $this->assertSame([10, 0], [$wallet->fresh()->balance_cached, $wallet->fresh()->reserved_cached]);

        $spent = $reserve(4, 'hold-d', 4);
        DB::transaction(fn () => app(CaptureCreditsAction::class)->execute($spent, 'succeeded'));
        DB::transaction(fn () => app(ReleaseCreditsAction::class)->execute($spent, 'late'));
        $this->assertSame([6, 0], [$wallet->fresh()->balance_cached, $wallet->fresh()->reserved_cached]);
        $this->assertSame((int) CreditLedgerEntry::query()->sum('amount'), $wallet->fresh()->balance_cached);
        $this->assertSame((int) CreditLedgerEntry::query()->sum('held_amount'), $wallet->fresh()->reserved_cached);
        $this->assertSame(['grant', 'reserve', 'release', 'reserve', 'capture'], CreditLedgerEntry::query()->orderBy('id')->pluck('type')->map->value->all());
    }

    public function test_the_database_refuses_an_overdrawn_wallet(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('The check constraint exists on PostgreSQL only.');
        }
        $workspace = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);
        $wallet = app(WalletRepositoryInterface::class)->ensureWallet($workspace);

        $this->expectException(QueryException::class);
        DB::table('wallets')->where('id', $wallet->id)->update(['reserved_cached' => 1]);
    }

    private function assertDomainError(string $code, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail("Expected {$code}.");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function test_gift_grant_is_disabled_when_config_is_zero(): void
    {
        config(['karmandeman.gift_credits' => 0]);

        $workspace = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);

        $entry = app(GrantInitialCreditsAction::class)->execute($workspace);

        $this->assertNull($entry);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
