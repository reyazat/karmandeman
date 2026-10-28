<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\AccountDeletionStatus;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One scheduler pass (NFR-12): purge new requests, complete those whose
 * backups expired, and alert on deadlines at risk. A failing request is
 * logged and retried next pass without blocking the others.
 */
final readonly class ProcessAccountDeletionsAction
{
    public function __construct(
        private AccountDeletionRepositoryInterface $deletions,
        private PurgeAccountDataAction $purge,
        private CompleteAccountDeletionAction $complete,
    ) {}

    /** @return array{purged: int, failed: int, completed: int, at_risk: int} */
    public function execute(CarbonImmutable $now): array
    {
        $batch = (int) config('karmandeman.erasure.batch_size');
        $summary = ['purged' => 0, 'failed' => 0, 'completed' => 0, 'at_risk' => 0];

        foreach ($this->deletions->withStatus(AccountDeletionStatus::Requested, $batch) as $deletion) {
            try {
                $this->purge->execute($deletion);
                $summary['purged']++;
            } catch (Throwable $exception) {
                $summary['failed']++;
                // Class name only: messages may carry identifiers.
                $this->deletions->recordFailure($deletion, $exception::class);
                Log::error('privacy.purge_failed', ['receipt' => $deletion->public_id, 'exception_type' => $exception::class]);
            }
        }

        foreach ($this->deletions->withStatus(AccountDeletionStatus::AwaitingBackupExpiry, $batch) as $deletion) {
            $summary['completed'] += $this->complete->execute($deletion, $now) ? 1 : 0;
        }

        $alertBefore = $now->addHours((int) config('karmandeman.erasure.alert_hours_before_deadline'));
        foreach ($this->deletions->unfinishedBefore($alertBefore) as $deletion) {
            $breached = $now->greaterThanOrEqualTo($deletion->deadline_at);
            // A purged receipt whose evidenced backups expire before the deadline is on track.
            $expected = $this->complete->expectedCompletion($deletion);
            if (! $breached && $expected !== null && $expected->lessThanOrEqualTo($deletion->deadline_at)) {
                continue;
            }
            $summary['at_risk']++;
            Log::critical($breached ? 'privacy.deletion_deadline_breached' : 'privacy.deletion_deadline_at_risk', [
                'receipt' => $deletion->public_id,
                'status' => $deletion->status->value,
                'deadline_at' => $deletion->deadline_at->toIso8601String(),
            ]);
        }

        return $summary;
    }
}
