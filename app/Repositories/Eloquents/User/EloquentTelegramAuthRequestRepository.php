<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\User;

use App\Models\TelegramAuthRequest;
use App\Models\User;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;

final class EloquentTelegramAuthRequestRepository implements TelegramAuthRequestRepositoryInterface
{
    public function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    public function find(User $user, string $key): ?TelegramAuthRequest
    {
        return TelegramAuthRequest::query()->where('user_id', $user->id)->where('key', $key)->first();
    }

    public function tokenIsActive(User $user, TelegramAuthRequest $record): bool
    {
        return $user->tokens()->whereKey($record->personal_access_token_id)
            ->where('expires_at', '>', now())->exists();
    }

    public function record(User $user, string $key, string $hash, string $token, int $tokenId): void
    {
        TelegramAuthRequest::create([
            'user_id' => $user->id,
            'key' => $key,
            'request_hash' => $hash,
            'encrypted_token' => $token,
            'personal_access_token_id' => $tokenId,
            'created_at' => now(),
        ]);
    }
}
