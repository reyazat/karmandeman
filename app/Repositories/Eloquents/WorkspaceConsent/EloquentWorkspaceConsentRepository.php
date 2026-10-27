<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\WorkspaceConsent;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConsent;
use App\Repositories\Contracts\WorkspaceConsentRepositoryInterface;

final class EloquentWorkspaceConsentRepository implements WorkspaceConsentRepositoryInterface
{
    public function find(Workspace $workspace, string $version): ?WorkspaceConsent
    {
        return WorkspaceConsent::query()->where('workspace_id', $workspace->id)
            ->where('policy_version', $version)->first();
    }

    public function record(Workspace $workspace, User $user, string $version, string $documentHash): WorkspaceConsent
    {
        return WorkspaceConsent::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'policy_version' => $version,
            'policy_document_hash' => $documentHash,
            'accepted_at' => now(),
        ]);
    }
}
