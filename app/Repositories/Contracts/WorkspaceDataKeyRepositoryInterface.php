<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Workspace;
use App\Models\WorkspaceDataKey;

interface WorkspaceDataKeyRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?WorkspaceDataKey;

    public function create(Workspace $workspace, string $transitKey, string $wrappedKey): WorkspaceDataKey;
}
