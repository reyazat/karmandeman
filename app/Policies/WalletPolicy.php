<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/** Reading the ledger; credits only move through ledger Actions, never a user mutation. */
final class WalletPolicy
{
    use AuthorizesWorkspaceRecords;

    public function view(User $user, Wallet $wallet): bool
    {
        return $this->ownsWorkspace($user, $wallet->workspace_id);
    }
}
