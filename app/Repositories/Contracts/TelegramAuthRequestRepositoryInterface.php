<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\TelegramAuthRequest;
use App\Models\User;

interface TelegramAuthRequestRepositoryInterface
{
    public function lock(User $user): void;

    public function find(User $user, string $key): ?TelegramAuthRequest;

    public function tokenIsActive(User $user, TelegramAuthRequest $record): bool;

    public function record(User $user, string $key, string $hash, string $token, int $tokenId): void;

    public function forgetFor(User $user): void;
}
