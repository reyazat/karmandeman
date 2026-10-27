<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface WorkspaceRepositoryInterface
{
    /** Lock the row and return its current state. */
    public function lock(Workspace $workspace): Workspace;

    public function markDeletionPending(Workspace $workspace): void;

    public function createForOwner(User $owner, array $attributes = []): Workspace;

    public function currentFor(User $user): ?Workspace;
}
