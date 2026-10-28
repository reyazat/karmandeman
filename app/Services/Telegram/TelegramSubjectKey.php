<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Encryption\Encrypter;

/**
 * Keyed digest of a Telegram user ID for records kept before an account is
 * known (conversation events), so the purge can find them without storing
 * the ID itself (ADR-014). The key derives from APP_KEY, which no database
 * dump or backup contains.
 */
final readonly class TelegramSubjectKey
{
    public function __construct(private Encrypter $encrypter) {}

    public function for(int|string $telegramId): string
    {
        return $this->digest('telegram:'.$telegramId);
    }

    /** Dedupe key of one chat message; keyed so a known chat ID cannot be matched to it. */
    public function message(int|string $chatId, int|string $messageId): string
    {
        return $this->digest("message:{$chatId}:{$messageId}");
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, hash_hkdf('sha256', $this->encrypter->getKey(), 32, 'km-telegram-subject'));
    }
}
