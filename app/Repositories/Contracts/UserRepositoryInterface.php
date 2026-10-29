<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Data\Telegram\TelegramUserData;
use App\Models\User;

interface UserRepositoryInterface
{
    public function findByIdentity(string $provider, string $externalId): ?User;

    public function createWithIdentity(TelegramUserData $telegramUser, string $provider): User;

    public function revokeAccessTokens(User $user): void;

    public function find(int $id): ?User;

    /** The private chat of the user's Telegram identity, which is its user ID. */
    public function telegramChatId(User $user): ?string;
}
