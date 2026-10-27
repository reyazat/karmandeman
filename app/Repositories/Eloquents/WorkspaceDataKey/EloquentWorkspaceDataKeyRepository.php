<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\WorkspaceDataKey;

use App\Models\Workspace;
use App\Models\WorkspaceDataKey;
use App\Repositories\Contracts\WorkspaceDataKeyRepositoryInterface;

final class EloquentWorkspaceDataKeyRepository implements WorkspaceDataKeyRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?WorkspaceDataKey
    {
        return WorkspaceDataKey::query()->where('workspace_id', $workspace->id)->first();
    }

    public function create(Workspace $workspace, string $transitKey, string $wrappedKey): WorkspaceDataKey
    {
        return WorkspaceDataKey::create([
            'workspace_id' => $workspace->id,
            'transit_key' => $transitKey,
            'wrapped_key' => $wrappedKey,
            'created_at' => now(),
        ]);
    }
}
