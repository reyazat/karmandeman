<?php

declare(strict_types=1);

namespace App\Data\Wallet;

use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;
use App\Models\Project;

/**
 * One ledger entry with what it paid for (FR-073): the run, its project and
 * output class when the entry belongs to a run; the project when it paid for
 * a catalogue file (ADR-052); otherwise null.
 */
final readonly class CreditUsageLine
{
    public function __construct(
        public CreditLedgerEntry $entry,
        public ?AiWorkflowRun $run = null,
        public ?string $outputClass = null,
        public ?Project $project = null,
    ) {}
}
