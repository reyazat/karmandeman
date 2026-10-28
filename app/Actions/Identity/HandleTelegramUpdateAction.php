<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Repositories\Contracts\ConversationEventRepositoryInterface;
use App\Services\Telegram\TelegramConversationService;
use App\Services\Telegram\TelegramSubjectKey;
use Illuminate\Support\Facades\DB;

final readonly class HandleTelegramUpdateAction
{
    public function __construct(
        private ConversationEventRepositoryInterface $events,
        private TelegramConversationService $conversation,
        private TelegramSubjectKey $subjects,
    ) {}

    public function execute(array $update): string
    {
        return DB::transaction(function () use ($update): string {
            $message = $update['message'] ?? null;
            $messageKey = is_array($message) && isset($message['message_id'], $message['chat']['id'])
                ? $this->subjects->message($message['chat']['id'], $message['message_id'])
                : null;
            $senderId = $message['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
            $event = $this->events->recordOnce([
                'channel' => 'telegram',
                'external_update_id' => (string) $update['update_id'],
                'message_key' => $messageKey,
                'subject_key' => is_scalar($senderId) ? $this->subjects->for($senderId) : null,
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
