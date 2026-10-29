<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Actions\Project\SendTelegramSnapshotAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The approved batch as a JSON file to the seller's chat (ADR-030). Carries
 * only ids: no chat ID or product data in the queue payload, so erasure of
 * the account rows is enough and the outbox purge needs no match for it.
 */
final class SendTelegramSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $userId,
        public string $projectPublicId,
        public int $snapshotNumber,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(SendTelegramSnapshotAction $send): void
    {
        // Older than the outbox limit: the seller asks again rather than receiving a stale file.
        $createdAt = $this->job?->payload()['createdAt'] ?? null;
        if (is_int($createdAt) && $createdAt <= now()->subHours((int) config('karmandeman.retention.queued_message_hours'))->getTimestamp()) {
            $this->delete();

            return;
        }
        $send->execute($this->userId, $this->projectPublicId, $this->snapshotNumber);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('telegram.snapshot_delivery_failed', ['exception_type' => $exception ? $exception::class : null]);
    }
}
