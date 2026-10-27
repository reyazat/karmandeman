<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnswerTelegramCallback implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public string $callbackId) {}

    public function handle(TelegramBotService $bot): void
    {
        $bot->answerCallback($this->callbackId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('telegram.callback_ack_failed', ['exception_type' => $exception ? $exception::class : null]);
    }
}
