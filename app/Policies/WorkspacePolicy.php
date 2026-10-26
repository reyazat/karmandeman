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
}
