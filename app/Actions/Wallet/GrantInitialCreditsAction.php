<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;

final readonly class GrantInitialCreditsAction
{
    public function __construct(
        private WalletRepositoryInterface $wallets,
    ) {}

    /**
     * Grant the one-time signup gift. Idempotent per wallet.
     */
    public function execute(Workspace $workspace): ?CreditLedgerEntry
    {
        $amount = (int) config('karmandeman.gift_credits', 0);

        if ($amount <= 0) {
            return null;
        }

        $wallet = $this->wallets->ensureWallet($workspace);
        $key = "gift:wallet:{$wallet->id}";
        $existing = $this->wallets->findEntry($wallet, $key);
        if ($existing !== null) {
            return $existing;
        }

        return $this->wallets->appendEntry(
            wallet: $wallet,
            type: CreditLedgerType::Grant,
            amount: $amount,
            idempotencyKey: $key,
            relatedType: 'workspace',
            relatedId: $workspace->id,
            reason: 'initial_gift',
        );
    }
}
