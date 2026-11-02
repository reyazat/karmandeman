<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Wallet\CaptureCreditsAction;
use App\Actions\Wallet\ReleaseCreditsAction;
use App\Enums\AiRunStatus;
use App\Enums\CreditLedgerType;
use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\CreditQuoteRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Billing\CreditTariff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The one way an open extraction run ends (ADR-032, ADR-039): the run is
 * closed and the credit held for it is settled in the same transaction. Only
 * a succeeded run spends, whether or not the seller later approves what it
 * produced; a failure, timeout or cancellation releases the hold (QA-09).
 * The charge is the run's real usage at the quote's tariff, at least the
 * class minimum and at most the held cap (ADR-042). The caller holds the
 * workspace and run locks.
 */
final readonly class CloseAIWorkflowRunAction
{
    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private WalletRepositoryInterface $wallets,
        private CaptureCreditsAction $capture,
        private ReleaseCreditsAction $release,
        private CreditQuoteRepositoryInterface $quotes,
        private CreditTariff $tariff,
    ) {}

    /**
     * @param  array<string, int|string>|null  $usage
     * @return int|null the credits spent, or null when none were
     */
    public function execute(AiWorkflowRun $run, AiRunStatus $status, ?string $errorCode = null, ?string $resultMac = null, ?int $candidates = null, ?array $usage = null): ?int
    {
        if ($status->isOpen()) {
            throw new LogicException('A run is closed with a final status.');
        }
        $this->runs->close($run, $status, CarbonImmutable::now(), $errorCode, $resultMac, $candidates, $usage);

        $hold = $run->reservation_entry_id === null ? null : $this->wallets->findEntryById($run->reservation_entry_id);
        if ($hold === null) {
            return null;
        }
        if ($status === AiRunStatus::Succeeded) {
            $settled = $this->capture->execute($hold, 'product_extraction_succeeded', $this->charge($run, $hold, $usage));
        } else {
            $settled = $this->release->execute($hold, 'product_extraction_'.$status->value);
        }

        return $settled->type === CreditLedgerType::Capture ? -$settled->amount : null;
    }

    /**
     * What the succeeded run costs. A fixed-price quote of ADR-039 (no stored
     * tariff) is spent whole. Without its quote the run pays only the class
     * minimum, never a guess at the cap. Above the cap only the cap is charged
     * and the gap is logged for reconciliation, since the seller never pays
     * more than they accepted.
     *
     * @param  array<string, int|string>|null  $usage
     */
    private function charge(AiWorkflowRun $run, CreditLedgerEntry $hold, ?array $usage): int
    {
        $quote = $run->quote_id === null ? null : $this->quotes->findForWorkspace($run->workspace_id, $run->quote_id);
        if ($quote !== null && $quote->tariff === null) {
            return $hold->held_amount;
        }
        $class = $this->tariff->classFor($run);
        if ($quote === null) {
            Log::warning('credit.quote_missing', ['run_id' => $run->public_id]);

            return min($hold->held_amount, $this->tariff->charge($this->tariff->current(), $class, null));
        }
        $charge = $this->tariff->charge($quote->tariff, $class, $usage);
        if ($charge > $hold->held_amount) {
            Log::warning('credit.charge_capped', ['run_id' => $run->public_id, 'charge' => $charge, 'cap' => $hold->held_amount]);

            return $hold->held_amount;
        }

        return $charge;
    }
}
