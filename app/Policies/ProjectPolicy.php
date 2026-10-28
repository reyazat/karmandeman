<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/** The pilot has one role, the active owner, so view and update grant the same today. */
final class ProjectPolicy
{
    use AuthorizesWorkspaceRecords;

    public function view(User $user, Project $project): bool
    {
        return $this->ownsWorkspace($user, $project->workspace_id);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->ownsWorkspace($user, $project->workspace_id);
    }
}
