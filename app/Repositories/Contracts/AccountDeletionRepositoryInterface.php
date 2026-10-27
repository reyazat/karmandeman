<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

interface AccountDeletionRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?AccountDeletion;

    public function create(Workspace $workspace, User $user, string $channel, CarbonImmutable $requestedAt, CarbonImmutable $deadlineAt): AccountDeletion;
}
