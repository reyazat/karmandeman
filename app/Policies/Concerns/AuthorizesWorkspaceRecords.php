<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;

/**
 * A record belongs to the user only through its own workspace_id. The workspace
 * is re-read by that key: a relation set by the caller (a repository sets the
 * requesting workspace to save a query) is never trusted as authority.
 */
trait AuthorizesWorkspaceRecords
{
    private function ownsWorkspace(User $user, int $workspaceId): bool
    {
        $workspace = Workspace::query()->find($workspaceId);

        return $workspace !== null && app(WorkspacePolicy::class)->access($user, $workspace);
    }
}
