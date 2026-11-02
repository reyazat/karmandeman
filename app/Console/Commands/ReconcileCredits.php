<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Wallet\ReconcileCreditLedgerAction;
use Illuminate\Console\Command;

final class ReconcileCredits extends Command
{
    protected $signature = 'credits:reconcile';

    protected $description = 'Check every wallet cache and credit hold against the ledger; reports, never corrects (FR-073, ADR-043)';

    public function handle(ReconcileCreditLedgerAction $reconcile): int
    {
        $result = $reconcile->execute();
        $this->line(json_encode([
            'wallets' => $result['wallets'],
            'holds' => $result['holds'],
            'mismatches' => count($result['mismatches']),
        ], JSON_THROW_ON_ERROR));

        // A failed exit makes the scheduler's failure hooks and monitoring see a broken ledger.
        return $result['mismatches'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
