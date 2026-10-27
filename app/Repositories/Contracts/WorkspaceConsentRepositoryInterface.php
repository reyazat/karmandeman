<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConsent;

interface WorkspaceConsentRepositoryInterface
{
    public function find(Workspace $workspace, string $version): ?WorkspaceConsent;

    public function record(Workspace $workspace, User $user, string $version, string $documentHash): WorkspaceConsent;
}
