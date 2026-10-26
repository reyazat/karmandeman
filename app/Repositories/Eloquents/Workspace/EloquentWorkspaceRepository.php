<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Workspace;

use App\Enums\MembershipRole;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;

final class EloquentWorkspaceRepository implements WorkspaceRepositoryInterface
{
    public function lock(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
    }

    public function createForOwner(User $owner, array $attributes = []): Workspace
    {
        $workspace = Workspace::create($attributes + [
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        $workspace->members()->attach($owner->id, ['role' => MembershipRole::Owner->value]);

        return $workspace;
    }

    public function currentFor(User $user): ?Workspace
    {
        return $user->workspaces()->orderBy('workspaces.id')->first();
    }
}
