<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Data\Privacy\ContainedAccount;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;

final readonly class FindContainedTelegramAccountAction
{
    public function __construct(
        private UserRepositoryInterface $users,
        private WorkspaceRepositoryInterface $workspaces,
        private AccountDeletionRepositoryInterface $deletions,
    ) {}

    /**
     * Read-only lookup that never registers: null for unknown or active
     * accounts, otherwise the contained account and its deletion receipt.
     */
    public function execute(int $telegramId): ?ContainedAccount
    {
        $user = $this->users->findByIdentity('telegram', (string) $telegramId);
        $workspace = $user === null ? null : $this->workspaces->currentFor($user);
        if ($workspace === null || $workspace->status === 'active') {
            return null;
        }

        return new ContainedAccount($this->deletions->forWorkspace($workspace));
    }
}
