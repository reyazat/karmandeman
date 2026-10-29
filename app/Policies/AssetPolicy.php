<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/** Upload is authorized on the project; an asset itself is only read. */
final class AssetPolicy
{
    use AuthorizesWorkspaceRecords;

    public function view(User $user, Asset $asset): bool
    {
        return $this->ownsWorkspace($user, $asset->workspace_id);
    }
}
