<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Actions\Employee\EnsureDefaultEmployeeAction;
use App\Actions\Wallet\GrantInitialCreditsAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Actions\Workspace\ProvisionWorkspaceAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class RegisterTelegramUserAction
{
    private const PROVIDER = 'telegram';

    public function __construct(
        private UserRepositoryInterface $users,
        private ProvisionWorkspaceAction $provisionWorkspace,
        private EnsureDefaultEmployeeAction $ensureEmployee,
        private GrantInitialCreditsAction $grantCredits,
        private WorkspaceRepositoryInterface $workspaces,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * Idempotent registration: resolve (or create) the account, provision the
     * default workspace with its first employee and wallet, and grant the
     * one-time gift credits. Repeated calls must not duplicate any of these.
     *
     * With $create false (Mini App sign-in, ADR-012) a missing account fails
     * inside this transaction, so a purge committing just before cannot make
     * sign-in create a fresh account.
     */
    public function execute(TelegramUserData $telegramUser, bool $create = true): User
    {
        return DB::transaction(function () use ($telegramUser, $create): User {
            $user = $this->users->findByIdentity(self::PROVIDER, (string) $telegramUser->id)
                ?? ($create ? $this->users->createWithIdentity($telegramUser, self::PROVIDER) : throw new DomainException('ACCOUNT_NOT_FOUND'));

            abort_unless($user->status === 'active', 403);
            // An existing workspace is checked under its row lock before any write (FR-086).
            $existing = $this->workspaces->currentFor($user);
            if ($existing !== null) {
                $this->lockActive->execute($existing);
            }
            $workspace = $this->provisionWorkspace->execute($user);
            Gate::forUser($user)->authorize('access', $workspace);
            $this->ensureEmployee->execute($workspace);
            $this->grantCredits->execute($workspace);

            return $user;
        }, attempts: 3);
    }
}
