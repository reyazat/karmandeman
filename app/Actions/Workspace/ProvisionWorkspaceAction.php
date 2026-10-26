<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;

final readonly class ProvisionWorkspaceAction
{
    public function __construct(
        private WorkspaceRepositoryInterface $workspaces,
        private WalletRepositoryInterface $wallets,
    ) {}

    /**
     * Create the default workspace for a new user, or return the existing one.
     */
    public function execute(User $user): Workspace
    {
        $workspace = $this->workspaces->currentFor($user)
            ?? $this->workspaces->createForOwner($user);
        $this->wallets->ensureWallet($workspace);

        return $workspace;
    }
}
