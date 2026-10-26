<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Workspace;

interface WorkspaceRepositoryInterface
{
    public function lock(Workspace $workspace): void;

    public function createForOwner(User $owner, array $attributes = []): Workspace;

    public function currentFor(User $user): ?Workspace;
}
