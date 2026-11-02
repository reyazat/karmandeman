<?php

declare(strict_types=1);

namespace App\Data\Wallet;

use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;

/**
 * One ledger entry with what it paid for (FR-073): the run, its project and
 * output class when the entry belongs to a run, otherwise null.
 */
final readonly class CreditUsageLine
{
    public function __construct(
        public CreditLedgerEntry $entry,
        public ?AiWorkflowRun $run = null,
        public ?string $outputClass = null,
    ) {}
}
