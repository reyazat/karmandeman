<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\AccountDeletion;

use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentAccountDeletionRepository implements AccountDeletionRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?AccountDeletion
    {
        return AccountDeletion::query()->where('workspace_id', $workspace->id)->first();
    }

    public function create(Workspace $workspace, User $user, string $channel, CarbonImmutable $requestedAt, CarbonImmutable $deadlineAt): AccountDeletion
    {
        return AccountDeletion::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'channel' => $channel,
            'status' => 'requested',
            'requested_at' => $requestedAt,
            'deadline_at' => $deadlineAt,
        ]);
    }
}
