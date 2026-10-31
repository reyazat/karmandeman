<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * Ends every open run past its deadline (ADR-032, FR-060): the batch reopens
 * and a callback that arrives later is ignored. Each run ends in its own
 * transaction, so one failure does not hold the others.
 */
final readonly class ExpireAIWorkflowRunsAction
{
    private const BATCH = 100;

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private FailAIWorkflowRunAction $fail,
    ) {}

    /** @return int the runs looked at */
    public function execute(CarbonImmutable $now): int
    {
        $ids = $this->runs->overdueIds($now, self::BATCH);
        foreach ($ids as $id) {
            $this->fail->execute($id, FailAIWorkflowRunAction::TIMED_OUT);
        }

        return count($ids);
    }
}
