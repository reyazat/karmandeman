<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin Bot API client. The token stays server-side only.
 */
final readonly class TelegramBotService
{
    public function sendMessage(int|string $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            throw new RuntimeException('Telegram delivery is not configured.');
        }

        try {
            $response = Http::connectTimeout(3)->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram delivery rejected.');
            }
        } catch (Throwable) {
            // HTTP exceptions include the token URL and must not reach failed_jobs/logs.
            throw new RuntimeException('Telegram delivery failed.');
        }
    }
}
