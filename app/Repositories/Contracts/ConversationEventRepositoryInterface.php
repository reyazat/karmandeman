<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ConversationEvent;

interface ConversationEventRepositoryInterface
{
    /**
     * Record an inbound channel event exactly once. Returns null when the
     * (channel, external_update_id) pair was already recorded (duplicate retry).
     */
    public function recordOnce(array $attributes): ?ConversationEvent;
}
