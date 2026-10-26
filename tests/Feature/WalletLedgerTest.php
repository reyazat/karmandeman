<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Wallet\GrantInitialCreditsAction;
use App\Enums\CreditLedgerType;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
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

    public function test_gift_grant_is_disabled_when_config_is_zero(): void
    {
        config(['karmandeman.gift_credits' => 0]);

        $workspace = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);

        $entry = app(GrantInitialCreditsAction::class)->execute($workspace);

        $this->assertNull($entry);
        $this->assertDatabaseCount('credit_ledger', 0);
    }
}
