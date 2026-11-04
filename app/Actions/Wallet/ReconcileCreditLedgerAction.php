<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\AiRunStatus;
use App\Enums\CreditLedgerType;
use App\Models\Artifact;
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
     * @param  array{held: int, settlement: string|null, settled_amount: int|null, settled_held: int|null, same_target: bool, run_status: string|null, run_workspace_id: int|null, wallet_workspace_id: int, related_run: bool, related_artifact: bool, artifact_status: string|null, artifact_workspace_id: int|null, artifact_current_hold: bool, artifact_paid: bool}  $hold
     * @return list<string>
     */
    private function holdChecks(array $hold): array
    {
        $checks = $hold['related_artifact'] ? $this->artifactChecks($hold) : [];
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

    /**
     * A catalogue file's hold (ADR-052): captured only for the file it made,
     * released when it failed or was replaced by a newer hold, and open only
     * while its file is still queued for the first time.
     *
     * @param  array{settlement: string|null, wallet_workspace_id: int, artifact_status: string|null, artifact_workspace_id: int|null, artifact_current_hold: bool, artifact_paid: bool}  $hold
     * @return list<string>
     */
    private function artifactChecks(array $hold): array
    {
        if ($hold['artifact_status'] === null) {
            return $hold['settlement'] === null ? ['hold_without_artifact'] : [];
        }
        $checks = $hold['artifact_workspace_id'] !== $hold['wallet_workspace_id'] ? ['artifact_of_another_workspace'] : [];
        // A file made once stays paid by this hold, also while it is made again after a loss.
        $made = $hold['artifact_current_hold'] && $hold['artifact_paid'];
        $open = $hold['artifact_current_hold'] && ! $made && $hold['artifact_status'] === Artifact::STATUS_QUEUED;

        return [...$checks, ...match (true) {
            $hold['settlement'] === null => $open ? [] : ['hold_unsettled'],
            $open => ['settled_open_artifact'],
            $made !== ($hold['settlement'] === CreditLedgerType::Capture->value) => ['settlement_disagrees_with_artifact'],
            default => [],
        }];
    }
}
