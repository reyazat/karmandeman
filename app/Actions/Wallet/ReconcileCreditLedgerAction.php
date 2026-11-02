<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\AiRunStatus;
use App\Enums\CreditLedgerType;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * The periodic ledger check (FR-073, ADR-043). Read-only: it reports every
 * broken invariant of ADR-039 and ADR-042 and corrects nothing, since only
 * a reasoned operator adjustment may move credit outside its use case.
 * Each finding is logged with internal ids and no seller content.
 */
final readonly class ReconcileCreditLedgerAction
{
    public function __construct(private WalletRepositoryInterface $wallets) {}

    /**
     * @return array{wallets: int, holds: int, mismatches: list<array{check: string, wallet_id: int, entry_id?: string}>}
     */
    public function execute(): array
    {
        $mismatches = [];
        foreach ($this->wallets->cacheDrift() as $drift) {
            if ($drift['balance_cached'] !== $drift['ledger_amount']) {
                $mismatches[] = ['check' => 'balance_cache_drift', 'wallet_id' => $drift['wallet_id']];
            }
            if ($drift['reserved_cached'] !== $drift['ledger_held']) {
                $mismatches[] = ['check' => 'reserved_cache_drift', 'wallet_id' => $drift['wallet_id']];
            }
        }

        $holds = 0;
        foreach ($this->wallets->holdsWithSettlements() as $hold) {
            $holds++;
            foreach ($this->holdChecks($hold) as $check) {
                $mismatches[] = ['check' => $check, 'wallet_id' => $hold['wallet_id'], 'entry_id' => $hold['hold_id']];
            }
        }
        foreach ($this->wallets->settlementsWithoutHold() as $orphan) {
            $mismatches[] = ['check' => 'settlement_without_hold', 'wallet_id' => $orphan['wallet_id'], 'entry_id' => $orphan['entry_id']];
        }

        foreach ($mismatches as $mismatch) {
            Log::error('credit.reconcile_mismatch', $mismatch);
        }

        return ['wallets' => $this->wallets->countWallets(), 'holds' => $holds, 'mismatches' => $mismatches];
    }

    /**
     * A hold is settled once, by its whole amount, and only after its run
     * ended: a capture spends between one credit and the hold, a release
     * spends nothing (ADR-039, ADR-042). Only a succeeded run captures, and a
     * settlement belongs to the same run as its hold.
     *
     * @param  array{held: int, settlement: string|null, settled_amount: int|null, settled_held: int|null, same_target: bool, run_status: string|null, run_workspace_id: int|null, wallet_workspace_id: int, related_run: bool}  $hold
     * @return list<string>
     */
    private function holdChecks(array $hold): array
    {
        $checks = [];
        $run = $hold['run_status'] === null ? null : AiRunStatus::tryFrom($hold['run_status']);
        if ($hold['related_run'] && $hold['run_workspace_id'] !== null && $hold['run_workspace_id'] !== $hold['wallet_workspace_id']) {
            $checks[] = 'run_of_another_workspace';
        }

        if ($hold['settlement'] === null) {
            if ($hold['related_run'] && $hold['run_status'] === null) {
                $checks[] = 'hold_without_run';
            } elseif ($run !== null && ! $run->isOpen()) {
                $checks[] = 'hold_unsettled';
            }

            return $checks;
        }

        if ($hold['settled_held'] !== -$hold['held']) {
            $checks[] = 'settlement_hold_mismatch';
        }
        if (! $hold['same_target']) {
            $checks[] = 'settlement_of_another_target';
        }
        $spent = -(int) $hold['settled_amount'];
        $checks = [...$checks, ...match ($hold['settlement']) {
            CreditLedgerType::Capture->value => $spent < 1 || $spent > $hold['held'] ? ['capture_outside_hold'] : [],
            CreditLedgerType::Release->value => $spent !== 0 ? ['release_moved_balance'] : [],
            default => ['settlement_of_unknown_type'],
        }];
        if ($run !== null && $run->isOpen()) {
            $checks[] = 'settled_open_run';
        } elseif ($run !== null && ($run === AiRunStatus::Succeeded) !== ($hold['settlement'] === CreditLedgerType::Capture->value)) {
            $checks[] = 'settlement_disagrees_with_run';
        }

        return $checks;
    }
}
