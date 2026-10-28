<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Carbon\CarbonImmutable;

interface TelegramOutboxRepositoryInterface
{
    /** Delete queued and failed outbound messages to one chat; returns the count. */
    public function forgetChat(int|string $chatId): int;

    /** Delete queued and failed outbound jobs first created before the cutoffs; retries keep the original clock. */
    public function forgetCreatedBefore(CarbonImmutable $queuedBefore, CarbonImmutable $failedBefore): int;
}
