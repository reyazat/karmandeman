<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Holds credit for paid work the seller accepted (PRD E08, FR-070, ADR-039).
 * The hold moves nothing out of the balance; it only stops the same credit
 * from being held twice. Runs in the caller's transaction, after the caller
 * took the workspace lock, so the wallet lock always comes second.
 */
final readonly class ReserveCreditsAction
{
    public function __construct(private WalletRepositoryInterface $wallets) {}

    /**
     * @throws DomainException INSUFFICIENT_CREDITS or IDEMPOTENCY_KEY_REUSED
     */
    public function execute(Workspace $workspace, int $amount, string $key, string $relatedType, int $relatedId, string $reason): CreditLedgerEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReserveCreditsAction requires an open transaction.');
        }
        if ($amount < 1) {
            throw new DomainException('UNSUPPORTED_LEDGER_OPERATION');
        }
        $wallet = $this->wallets->lockForWorkspace($workspace) ?? throw new DomainException('INSUFFICIENT_CREDITS');

        $existing = $this->wallets->findEntry($wallet, $key);
        if ($existing !== null) {
            if ($existing->type !== CreditLedgerType::Reserve || $existing->held_amount !== $amount
                || $existing->related_type !== $relatedType || $existing->related_id !== $relatedId) {
                throw new DomainException('IDEMPOTENCY_KEY_REUSED');
            }

            return $existing;
        }
        if ($wallet->available() < $amount) {
            throw new DomainException('INSUFFICIENT_CREDITS');
        }

        return $this->wallets->record($wallet, CreditLedgerType::Reserve, 0, $amount, $key, $relatedType, $relatedId, $reason);
    }
}
