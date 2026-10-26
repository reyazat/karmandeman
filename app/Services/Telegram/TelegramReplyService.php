<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\Telegram\SendTelegramMessage;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class TelegramReplyService
{
    public function __construct(private Dispatcher $bus) {}

    public function sendMessage(int|string $chatId, string $text): void
    {
        // Internal DB insert shares the domain transaction. Worker sends only after commit.
        $this->bus->dispatch((new SendTelegramMessage($chatId, $text))
            ->onConnection('telegram')->onQueue('telegram')->beforeCommit());
    }
}
