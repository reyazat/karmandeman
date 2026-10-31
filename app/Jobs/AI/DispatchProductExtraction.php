<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\Project\DispatchExtractionRunAction;
use App\Actions\Project\FailAIWorkflowRunAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts one n8n extraction run. Carries only the run id: no chat id,
 * product text or file in the queue payload (ADR-032).
 */
final class DispatchProductExtraction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(DispatchExtractionRunAction $dispatch): void
    {
        $dispatch->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ai.run_dispatch_failed', ['exception_type' => $exception ? $exception::class : null]);
        app(FailAIWorkflowRunAction::class)->execute($this->runId, 'DISPATCH_FAILED');
    }
}
