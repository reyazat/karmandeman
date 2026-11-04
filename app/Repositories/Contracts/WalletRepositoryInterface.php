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

    public function findEntryById(int $id): ?CreditLedgerEntry;

    /** Row lock on the workspace's wallet, null without one; the caller holds the workspace lock. */
    public function lockForWorkspace(Workspace $workspace): ?Wallet;

    /** Row lock on a wallet by id; the caller holds the workspace lock. */
    public function lock(int $walletId): Wallet;

    /**
     * Append an entry to a wallet the caller has locked and move both caches
     * by its amounts (ADR-039). The caller has checked the business rules.
     */
    public function record(
        Wallet $locked,
        CreditLedgerType $type,
        int $amount,
        int $heldAmount,
        string $idempotencyKey,
        string $relatedType,
        int $relatedId,
        string $reason,
    ): CreditLedgerEntry;

    /**
     * @return Collection<int, CreditLedgerEntry>
     */
    public function entries(Wallet $wallet, int $limit = 50): Collection;

    /**
     * Wallets whose cached balance or hold differs from the sum of their
     * ledger (ADR-039 invariant), in one statement so a commit in flight
     * cannot show a false drift.
     *
     * @return list<array{wallet_id: int, balance_cached: int, reserved_cached: int, ledger_amount: int, ledger_held: int}>
     */
    public function cacheDrift(): array;

    /**
     * Every hold with its settlement, if any, and the run it was made for,
     * read in one statement, ordered by hold id.
     *
     * and the catalogue file it held for (ADR-052).
     *
     * @return iterable<array{hold_id: string, wallet_id: int, held: int, settlement: string|null, settled_amount: int|null, settled_held: int|null, same_target: bool, run_status: string|null, run_workspace_id: int|null, wallet_workspace_id: int, related_run: bool, related_artifact: bool, artifact_status: string|null, artifact_workspace_id: int|null, artifact_current_hold: bool, artifact_paid: bool}>
     */
    public function holdsWithSettlements(): iterable;

    /**
     * Captures and releases with no hold of their wallet behind their key.
     *
     * @return list<array{entry_id: string, wallet_id: int}>
     */
    public function settlementsWithoutHold(): array;

    public function countWallets(): int;
}
