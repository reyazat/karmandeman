<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface AccountErasureRepositoryInterface
{
    /** Telegram user IDs linked to the account, read before its identities go. */
    public function telegramIds(User $user): array;

    /** P0 purge covers only a user who owns and belongs to this one workspace and nothing else. */
    public function isSingleOwnerAccount(Workspace $workspace, User $user): bool;

    /**
     * Delete every live row of a single-owner account in dependency order
     * (FR-087). Must run inside the caller's transaction, after locking.
     *
     * @param  list<string>  $subjectKeys  conversation-event digests of the account's Telegram IDs
     */
    public function purge(Workspace $workspace, User $user, array $subjectKeys): void;
}
