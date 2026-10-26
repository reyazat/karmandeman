<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Actions\Employee\EnsureDefaultEmployeeAction;
use App\Actions\Wallet\GrantInitialCreditsAction;
use App\Actions\Workspace\ProvisionWorkspaceAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
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
    ) {}

    /**
     * Idempotent registration: resolve (or create) the account, provision the
     * default workspace with its first employee and wallet, and grant the
     * one-time gift credits. Repeated calls must not duplicate any of these.
     */
    public function execute(TelegramUserData $telegramUser): User
    {
        return DB::transaction(function () use ($telegramUser): User {
            $user = $this->users->findByIdentity(self::PROVIDER, (string) $telegramUser->id)
                ?? $this->users->createWithIdentity($telegramUser, self::PROVIDER);

            abort_unless($user->status === 'active', 403);
            $workspace = $this->provisionWorkspace->execute($user);
            Gate::forUser($user)->authorize('access', $workspace);
            $this->ensureEmployee->execute($workspace);
            $this->grantCredits->execute($workspace);

            return $user;
        }, attempts: 3);
    }
}
