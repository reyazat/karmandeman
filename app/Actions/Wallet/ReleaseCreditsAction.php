<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\CreditLedgerType;
use App\Models\CreditLedgerEntry;
use App\Repositories\Contracts\WalletRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ends a hold whose work failed, timed out or was cancelled (PRD E08, QA-09,
 * ADR-039): the balance is untouched and the held credit is spendable again.
 * It never adds credit. Shares the settlement key with CaptureCreditsAction,
 * so a hold is released or captured exactly once.
 */
final readonly class ReleaseCreditsAction
{
    public function __construct(private WalletRepositoryInterface $wallets) {}

    public function execute(CreditLedgerEntry $reservation, string $reason): CreditLedgerEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ReleaseCreditsAction requires an open transaction.');
        }
        if ($reservation->type !== CreditLedgerType::Reserve) {
            throw new DomainException('UNSUPPORTED_LEDGER_OPERATION');
        }
        $wallet = $this->wallets->lock($reservation->wallet_id);
        $key = CaptureCreditsAction::settlementKey($reservation);

        return $this->wallets->findEntry($wallet, $key) ?? $this->wallets->record(
            $wallet, CreditLedgerType::Release, 0, -$reservation->held_amount, $key,
            (string) $reservation->related_type, (int) $reservation->related_id, $reason,
        );
    }
}
