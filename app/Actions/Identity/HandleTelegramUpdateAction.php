<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Repositories\Contracts\ConversationEventRepositoryInterface;
use App\Services\Telegram\TelegramConversationService;
use Illuminate\Support\Facades\DB;

final readonly class HandleTelegramUpdateAction
{
    public function __construct(
        private ConversationEventRepositoryInterface $events,
        private TelegramConversationService $conversation,
    ) {}

    public function execute(array $update): string
    {
        return DB::transaction(function () use ($update): string {
            $event = $this->events->recordOnce([
                'channel' => 'telegram',
                'external_update_id' => (string) $update['update_id'],
                'redacted_body' => [
                    'update_id' => $update['update_id'],
                    'has_message' => isset($update['message']),
                    'chat_type' => $update['message']['chat']['type'] ?? null,
                ],
            ]);

            if ($event === null) {
                return 'duplicate';
            }

            $this->conversation->handleUpdate($update);

            return 'ok';
        }, attempts: 3);
    }
}
