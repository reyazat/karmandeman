<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class IssueTelegramTokenAction
{
    public function __construct(
        private RegisterTelegramUserAction $register,
        private UserRepositoryInterface $users,
        private TelegramAuthRequestRepositoryInterface $requests,
        private WorkspaceRepositoryInterface $workspaces,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * Sign-in only: accounts are created by the bot's /start flow, never by
     * opening the Mini App, so an unknown Telegram user gets no account or gift.
     *
     * @return array{User, string}
     */
    public function execute(TelegramUserData $telegramUser, string $initData, string $key): array
    {
        if ($this->users->findByIdentity('telegram', (string) $telegramUser->id) === null) {
            throw new DomainException('ACCOUNT_NOT_FOUND');
        }
        $account = $this->register->execute($telegramUser);
        $hash = hash('sha256', $initData);

        return DB::transaction(function () use ($account, $key, $hash): array {
            // Global lock order: user, then workspace (registration and deletion match).
            // Deletion may commit between registration and here, so re-check under lock.
            $this->requests->lock($account);
            $this->lockActive->execute($this->workspaces->currentFor($account) ?? throw new DomainException('WORKSPACE_NOT_FOUND'));
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
