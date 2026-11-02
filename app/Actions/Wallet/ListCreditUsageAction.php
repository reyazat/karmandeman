<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Data\Wallet\CreditUsage;
use App\Data\Wallet\CreditUsageLine;
use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;
use App\Models\Wallet;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Billing\CreditTariff;
use InvalidArgumentException;

/**
 * The seller's latest ledger entries, each tied to the project, run and
 * output class it paid for (FR-073). Runs are looked up in the wallet's own
 * workspace only. Read-only: nothing here moves credit; the caller has
 * authorized the wallet.
 */
final readonly class ListCreditUsageAction
{
    public function __construct(
        private WalletRepositoryInterface $wallets,
        private AiWorkflowRunRepositoryInterface $runs,
        private CreditTariff $tariff,
    ) {}

    public function execute(Wallet $wallet, int $limit = 50): CreditUsage
    {
        $entries = $this->wallets->entries($wallet, $limit);
        $runIds = $entries
            ->filter(fn (CreditLedgerEntry $entry): bool => $entry->related_type === CreditLedgerEntry::RELATED_RUN && $entry->related_id !== null)
            ->map(fn (CreditLedgerEntry $entry): int => (int) $entry->related_id)
            ->unique()->values()->all();
        $runs = $this->runs->forWorkspaceByIds($wallet->workspace_id, $runIds);

        $lines = $entries->map(function (CreditLedgerEntry $entry) use ($runs): CreditUsageLine {
            $run = $entry->related_type === CreditLedgerEntry::RELATED_RUN ? $runs->get((int) $entry->related_id) : null;

            return new CreditUsageLine($entry, $run, $run === null ? null : $this->outputClass($run));
        })->values()->all();

        return new CreditUsage($wallet, $lines);
    }

    /** A workflow without an output class still lists; its entries just name none. */
    private function outputClass(AiWorkflowRun $run): ?string
    {
        try {
            return $this->tariff->classFor($run);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
