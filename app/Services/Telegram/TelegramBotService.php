<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Exceptions\TelegramFileTooLarge;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin Bot API client. The token stays server-side only.
 */
final readonly class TelegramBotService
{
    public function sendMessage(int|string $chatId, string $text, array $buttons = []): void
    {
        $token = config('services.telegram.bot_token');

        if (empty($token)) {
            throw new RuntimeException('Telegram delivery is not configured.');
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
            ];
            if ($buttons !== []) {
                $payload['reply_markup'] = ['inline_keyboard' => $buttons];
            }
            $response = Http::connectTimeout(3)->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram delivery rejected.');
            }
        } catch (Throwable) {
            // HTTP exceptions include the token URL and must not reach failed_jobs/logs.
            throw new RuntimeException('Telegram delivery failed.');
        }
    }

    public function answerCallback(string $callbackId): void
    {
        $token = config('services.telegram.bot_token');
        if (empty($token)) {
            throw new RuntimeException('Telegram delivery is not configured.');
        }
        try {
            $response = Http::connectTimeout(3)->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                    'callback_query_id' => $callbackId,
                ]);
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram callback rejected.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Telegram callback acknowledgement failed.');
        }
    }

    /**
     * Bot API file metadata (ADR-024). The file path is valid for about an hour.
     *
     * @return array{path: string, size: int|null}
     */
    public function getFile(#[\SensitiveParameter] string $fileId): array
    {
        $token = $this->token();
        try {
            $response = Http::connectTimeout(3)->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId]);
            $path = $response->json('result.file_path');
            if (! $response->successful() || $response->json('ok') !== true || ! is_string($path) || $path === '') {
                throw new RuntimeException('Telegram file lookup rejected.');
            }
            $size = $response->json('result.file_size');

            return ['path' => $path, 'size' => is_int($size) ? $size : null];
        } catch (Throwable) {
            throw new RuntimeException('Telegram file lookup failed.');
        }
    }

    /**
     * Server-side download, refused above the byte cap. Throws TelegramFileTooLarge
     * so the caller can tell the user instead of retrying.
     */
    public function downloadFile(string $path, int $maxBytes): string
    {
        $token = $this->token();
        try {
            $response = Http::connectTimeout(3)->timeout(30)
                ->withOptions(['on_headers' => function ($headers) use ($maxBytes): void {
                    if ((int) $headers->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new TelegramFileTooLarge;
                    }
                }])
                ->get("https://api.telegram.org/file/bot{$token}/".ltrim($path, '/'));
            if (! $response->successful()) {
                throw new RuntimeException('Telegram file download rejected.');
            }
            $body = $response->body();
        } catch (Throwable $e) {
            // Guzzle wraps the on_headers refusal more than one level deep.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof TelegramFileTooLarge) {
                    throw new TelegramFileTooLarge;
                }
            }
            throw new RuntimeException('Telegram file download failed.');
        }
        if (strlen($body) > $maxBytes) {
            throw new TelegramFileTooLarge;
        }

        return $body;
    }

    private function token(): string
    {
        $token = config('services.telegram.bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram delivery is not configured.');
        }

        return $token;
    }
}
