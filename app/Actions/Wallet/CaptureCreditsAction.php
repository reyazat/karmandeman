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
 * Spends a hold once its work succeeded (PRD E08, FR-071, ADR-039): the held
 * amount leaves the balance. A hold is settled once, by a capture or by a
 * release; both share one idempotency key, so the second settlement of
 * either kind returns the first. Runs in the caller's transaction after the
 * workspace lock.
 */
final readonly class CaptureCreditsAction
{
    public function __construct(private WalletRepositoryInterface $wallets) {}

    public function execute(CreditLedgerEntry $reservation, string $reason): CreditLedgerEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CaptureCreditsAction requires an open transaction.');
        }
        if ($reservation->type !== CreditLedgerType::Reserve) {
            throw new DomainException('UNSUPPORTED_LEDGER_OPERATION');
        }
        $wallet = $this->wallets->lock($reservation->wallet_id);
        $key = self::settlementKey($reservation);

        return $this->wallets->findEntry($wallet, $key) ?? $this->wallets->record(
            $wallet, CreditLedgerType::Capture, -$reservation->held_amount, -$reservation->held_amount, $key,
            (string) $reservation->related_type, (int) $reservation->related_id, $reason,
        );
    }

    /** The one key every settlement of this hold uses. */
    public static function settlementKey(CreditLedgerEntry $reservation): string
    {
        return "credit:settle:{$reservation->id}";
    }
}
