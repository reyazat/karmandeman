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

    public function __construct(public int|string $chatId, public string $text) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TelegramBotService $bot): void
    {
        $bot->sendMessage($this->chatId, $this->text);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('telegram.delivery_failed', ['exception_type' => $exception ? $exception::class : null]);
    }
}
