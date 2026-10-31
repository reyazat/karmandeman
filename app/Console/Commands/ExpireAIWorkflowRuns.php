<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\ExpireAIWorkflowRunsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExpireAIWorkflowRuns extends Command
{
    protected $signature = 'ai:expire-runs';

    protected $description = 'Time out n8n extraction runs past their deadline and reopen their batches (ADR-032)';

    public function handle(ExpireAIWorkflowRunsAction $expire): int
    {
        $this->line(json_encode(['expired' => $expire->execute(CarbonImmutable::now())], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
