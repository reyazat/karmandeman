<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\User;

use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function findByIdentity(string $provider, string $externalId): ?User
    {
        return User::query()
            ->whereHas('identities', function ($query) use ($provider, $externalId): void {
                $query->where('provider', $provider)->where('external_id', $externalId);
            })
            ->lock('for no key update')->first();
    }

    public function createWithIdentity(TelegramUserData $telegramUser, string $provider): User
    {
        try {
            return DB::transaction(function () use ($telegramUser, $provider): User {
                // Data minimisation (ADR-015): only the Telegram ID is needed to find
                // the account; display name, username and language are never stored.
                $user = User::create(['status' => 'active']);

                $user->identities()->create([
                    'provider' => $provider,
                    'external_id' => (string) $telegramUser->id,
                    'verified_at' => now(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->findByIdentity($provider, (string) $telegramUser->id) ?? throw $exception;
        }
    }

    public function revokeAccessTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
