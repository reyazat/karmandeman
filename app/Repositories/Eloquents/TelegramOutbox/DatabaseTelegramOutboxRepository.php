<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\TelegramOutbox;

use App\Jobs\Telegram\SendTelegramMessage;
use App\Repositories\Contracts\TelegramOutboxRepositoryInterface;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The Telegram outbox lives in the database queue (ADR-008) with payloads
 * encrypted under APP_KEY, so rows can only be matched to a chat after
 * decryption. Only SendTelegramMessage carries a chat ID; callback answers
 * hold a short-lived callback ID and are not attributable (ADR-014).
 *
 * A worker re-inserts a reserved job when it releases or fails it, so rows
 * are locked before the decision (the worker reserves with SKIP LOCKED) and
 * a chat with a message still being delivered is not forgotten yet.
 */
final readonly class DatabaseTelegramOutboxRepository implements TelegramOutboxRepositoryInterface
{
    public function __construct(private Encrypter $encrypter) {}

    public function forgetChat(int|string $chatId): int
    {
        $matches = fn (object $row): bool => $this->chatOf($row->payload) === (string) $chatId;
        // A reservation older than retry_after belongs to a worker killed by the job timeout.
        $liveSince = CarbonImmutable::now()->subSeconds((int) config('queue.connections.telegram.retry_after'))->getTimestamp();

        return DB::transaction(function () use ($matches, $liveSince): int {
            $deleted = $this->forget($this->jobsTable(), function (object $row) use ($matches, $liveSince): bool {
                if (! $matches($row)) {
                    return false;
                }
                if ($row->reserved_at !== null && (int) $row->reserved_at > $liveSince) {
                    throw new RuntimeException('A message to the chat is being delivered; retry later.');
                }

                return true;
            });

            return $deleted + $this->forget($this->failedTable(), $matches);
        });
    }

    public function forgetCreatedBefore(CarbonImmutable $queuedBefore, CarbonImmutable $failedBefore): int
    {
        // The row's created_at restarts on every release; the payload keeps the first push time.
        $olderThan = fn (CarbonImmutable $cutoff): Closure => fn (object $row): bool => $this->createdAt($row) < $cutoff->getTimestamp();

        return $this->forget($this->jobsTable(), $olderThan($queuedBefore)) + $this->forget($this->failedTable(), $olderThan($failedBefore));
    }

    /** @param Closure(object): bool $matches */
    private function forget(string $table, Closure $matches): int
    {
        $deleted = 0;
        DB::table($table)->where('queue', (string) config('queue.connections.telegram.queue'))->orderBy('id')->lockForUpdate()
            ->chunkById(200, function ($rows) use ($table, $matches, &$deleted): void {
                $ids = $rows->filter($matches)->pluck('id');
                $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }

    private function jobsTable(): string
    {
        return (string) config('queue.connections.telegram.table');
    }

    private function failedTable(): string
    {
        return (string) config('queue.failed.table');
    }

    private function createdAt(object $row): int
    {
        $createdAt = json_decode($row->payload, true)['createdAt'] ?? null;

        // An unreadable payload counts as old: it cannot be delivered or attributed anyway.
        return is_int($createdAt) ? $createdAt : 0;
    }

    private function chatOf(string $payload): ?string
    {
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            if (($data['data']['commandName'] ?? null) !== SendTelegramMessage::class) {
                return null;
            }
            $job = unserialize($this->encrypter->decrypt($data['data']['command']), ['allowed_classes' => [SendTelegramMessage::class]]);
        } catch (Throwable) {
            return null;
        }

        return $job instanceof SendTelegramMessage ? (string) $job->chatId : null;
    }
}
