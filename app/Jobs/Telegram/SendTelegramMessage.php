<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendTelegramMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(
        public int|string $chatId,
        public string $text,
        public array $buttons = [],
    ) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TelegramBotService $bot): void
    {
        if ($this->expired()) {
            $this->drop();

            return;
        }
        try {
            $bot->sendMessage($this->chatId, $this->text, $this->buttons);
        } catch (Throwable $exception) {
            // A release or failure would store the chat ID and text again after
            // retention may already have deleted this row (ADR-015).
            if ($this->expired()) {
                $this->drop();

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('telegram.delivery_failed', ['exception_type' => $exception ? $exception::class : null]);
    }

    /** @phpstan-impure Depends on the clock, which moves during delivery. */
    private function expired(): bool
    {
        $createdAt = $this->job?->payload()['createdAt'] ?? null;
        $cutoff = now()->subHours((int) config('karmandeman.retention.queued_message_hours'))->getTimestamp();

        return is_int($createdAt) && $createdAt <= $cutoff;
    }

    private function drop(): void
    {
        $this->delete();
        Log::warning('telegram.delivery_expired');
    }
}
