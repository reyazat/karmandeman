<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

interface TelegramOutboxRepositoryInterface
{
    /** Delete queued and failed outbound messages to one chat; returns the count. */
    public function forgetChat(int|string $chatId): int;
}
