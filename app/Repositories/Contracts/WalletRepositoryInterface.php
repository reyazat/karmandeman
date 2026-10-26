<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use App\Models\Workspace;
use Illuminate\Support\Collection;

interface WalletRepositoryInterface
{
    public function findEntry(Wallet $wallet, string $idempotencyKey): ?CreditLedgerEntry;

    public function ensureWallet(Workspace $workspace): Wallet;

    public function forWorkspace(Workspace $workspace): ?Wallet;

    /**
     * Append an entry and update the cached balance atomically.
     * Returns the previously stored entry when the idempotency key already exists.
     */
    public function appendEntry(
        Wallet $wallet,
        CreditLedgerType $type,
        int $amount,
        string $idempotencyKey,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $reason = null,
    ): CreditLedgerEntry;

    /**
     * @return Collection<int, CreditLedgerEntry>
     */
    public function entries(Wallet $wallet, int $limit = 50): Collection;
}
