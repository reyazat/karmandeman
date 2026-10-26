<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\ConversationEvent;

use App\Models\ConversationEvent;
use App\Repositories\Contracts\ConversationEventRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentConversationEventRepository implements ConversationEventRepositoryInterface
{
    public function recordOnce(array $attributes): ?ConversationEvent
    {
        if (isset($attributes['external_update_id'])) {
            $existing = ConversationEvent::query()
                ->where('channel', $attributes['channel'])
                ->where('external_update_id', $attributes['external_update_id'])
                ->first();

            if ($existing !== null) {
                return null;
            }
        }

        try {
            return DB::transaction(fn () => ConversationEvent::create($attributes + ['created_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
