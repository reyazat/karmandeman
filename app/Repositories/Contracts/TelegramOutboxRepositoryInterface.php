<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Carbon\CarbonImmutable;

interface TelegramOutboxRepositoryInterface
{
    /**
     * Delete queued and failed outbound messages to one chat; returns the count.
     * Throws while a worker still holds a live reservation on one of them.
     */
    public function forgetChat(int|string $chatId): int;

    /**
     * Delete queued and failed outbound jobs first created before the cutoffs; retries keep the original clock.
     * A job in flight drops itself once past the queued cutoff instead of being re-stored.
     */
    public function forgetCreatedBefore(CarbonImmutable $queuedBefore, CarbonImmutable $failedBefore): int;
}
