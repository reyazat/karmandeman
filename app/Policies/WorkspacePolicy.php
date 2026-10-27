<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

final class WorkspacePolicy
{
    public function access(User $user, Workspace $workspace): bool
    {
        return $user->status === 'active'
            && $workspace->status === 'active'
            && $workspace->owner_id === $user->id
            && $workspace->members()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }

    /** Owner may request (or replay) erasure while active or already contained. */
    public function requestDeletion(User $user, Workspace $workspace): bool
    {
        return $user->status === 'active'
            && in_array($workspace->status, ['active', 'deletion_pending'], true)
            && $workspace->owner_id === $user->id
            && $workspace->members()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }
}
