<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class IssueTelegramTokenAction
{
    public function __construct(
        private RegisterTelegramUserAction $register,
        private TelegramAuthRequestRepositoryInterface $requests,
    ) {}

    /** @return array{User, string} */
    public function execute(TelegramUserData $telegramUser, string $initData, string $key): array
    {
        $account = $this->register->execute($telegramUser);
        $hash = hash('sha256', $initData);

        return DB::transaction(function () use ($account, $key, $hash): array {
            $this->requests->lock($account);
            $record = $this->requests->find($account, $key);
            if ($record !== null) {
                if (! hash_equals($record->request_hash, $hash)) {
                    throw new DomainException('IDEMPOTENCY_KEY_CONFLICT');
                }
                if (! $this->requests->tokenIsActive($account, $record)) {
                    throw new DomainException('AUTH_REPLAY_EXPIRED');
                }

                return [$account, $record->encrypted_token];
            }

            $issued = $account->createToken('telegram-webapp', ['*'], now()->addHours(8));
            $this->requests->record($account, $key, $hash, $issued->plainTextToken, $issued->accessToken->id);

            return [$account, $issued->plainTextToken];
        });
    }
}
