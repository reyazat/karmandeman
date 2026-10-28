<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\TelegramOutbox;

use App\Jobs\Telegram\SendTelegramMessage;
use App\Repositories\Contracts\TelegramOutboxRepositoryInterface;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Telegram outbox lives in the database queue (ADR-008) with payloads
 * encrypted under APP_KEY, so rows can only be matched to a chat after
 * decryption. Only SendTelegramMessage carries a chat ID; callback answers
 * hold a short-lived callback ID and are not attributable (ADR-014).
 */
final readonly class DatabaseTelegramOutboxRepository implements TelegramOutboxRepositoryInterface
{
    public function __construct(private Encrypter $encrypter) {}

    public function forgetChat(int|string $chatId): int
    {
        $matches = fn (object $row): bool => $this->chatOf($row->payload) === (string) $chatId;

        return $this->forget($this->jobsTable(), $matches) + $this->forget($this->failedTable(), $matches);
    }

    /** @param Closure(object): bool $matches */
    private function forget(string $table, Closure $matches): int
    {
        $deleted = 0;
        DB::table($table)->where('queue', (string) config('queue.connections.telegram.queue'))->orderBy('id')
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
