<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Data\Telegram\TelegramUserData;
use App\Models\AccountDeletion;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class RequestReauthenticatedAccountDeletionAction
{
    public function __construct(
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private RequestAccountDeletionAction $requestDeletion,
    ) {}

    /**
     * The session token alone is not enough: the fresh signed Telegram user
     * must be the same account (FR-085), then the shared FR-086 flow runs.
     *
     * @return array{AccountDeletion, bool}
     */
    public function execute(User $user, TelegramUserData $reauthenticated): array
    {
        if ($this->users->findByIdentity('telegram', (string) $reauthenticated->id)?->id !== $user->id) {
            throw new AuthorizationException('REAUTHENTICATION_MISMATCH');
        }
        $workspace = $this->workspaces->currentFor($user) ?? throw new AuthorizationException('WORKSPACE_NOT_FOUND');

        return $this->requestDeletion->execute($workspace, $user, 'mini_app');
    }
}
