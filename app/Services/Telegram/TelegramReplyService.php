<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class TelegramReplyService
{
    public function __construct(private Dispatcher $bus) {}

    public function sendMessage(int|string $chatId, string $text, array $buttons = []): void
    {
        // Internal DB insert shares the domain transaction. Worker sends only after commit.
        $this->bus->dispatch((new SendTelegramMessage($chatId, $text, $buttons))
            ->onConnection('telegram')->onQueue('telegram')->beforeCommit());
    }

    public function acknowledge(string $callbackId): void
    {
        $this->bus->dispatch((new AnswerTelegramCallback($callbackId))
            ->onConnection('telegram')->onQueue('telegram')->beforeCommit());
    }
}
