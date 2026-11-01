<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Wallet\CaptureCreditsAction;
use App\Actions\Wallet\ReleaseCreditsAction;
use App\Enums\AiRunStatus;
use App\Enums\CreditLedgerType;
use App\Models\AiWorkflowRun;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The one way an open extraction run ends (ADR-032, ADR-039): the run is
 * closed and the credit held for it is settled in the same transaction. Only
 * a succeeded run spends it; a failure, timeout or cancellation releases it
 * (QA-09). The caller holds the workspace and run locks.
 */
final readonly class CloseAIWorkflowRunAction
{
    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private WalletRepositoryInterface $wallets,
        private CaptureCreditsAction $capture,
        private ReleaseCreditsAction $release,
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
            $settled = $this->capture->execute($hold, 'product_extraction_succeeded');
        } else {
            $settled = $this->release->execute($hold, 'product_extraction_'.$status->value);
        }

        return $settled->type === CreditLedgerType::Capture ? -$settled->amount : null;
    }
}
