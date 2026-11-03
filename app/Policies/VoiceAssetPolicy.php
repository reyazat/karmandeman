<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VoiceAsset;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

final class VoiceAssetPolicy
{
    use AuthorizesWorkspaceRecords;

    public function view(User $user, VoiceAsset $voice): bool
    {
        return $this->ownsWorkspace($user, $voice->workspace_id);
    }
}
