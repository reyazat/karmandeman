<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\TelegramMediaIntake;

use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentTelegramMediaIntakeRepository implements TelegramMediaIntakeRepositoryInterface
{
    public function createOnce(Workspace $workspace, Project $project, User $user, string $fileRef, ?int $declaredSize, string $messageKey): ?TelegramMediaIntake
    {
        $created = TelegramMediaIntake::query()->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'file_ref' => $fileRef,
            'declared_size' => $declaredSize,
            'message_key' => $messageKey,
            'created_at' => now(),
        ]);

        return $created === 0 ? null : TelegramMediaIntake::query()->where('message_key', $messageKey)->first();
    }

    public function find(int $id): ?TelegramMediaIntake
    {
        return TelegramMediaIntake::query()->find($id);
    }

    public function pendingForProject(Project $project): bool
    {
        return TelegramMediaIntake::query()->where('workspace_id', $project->workspace_id)->where('project_id', $project->id)->exists();
    }

    public function delete(TelegramMediaIntake $intake): void
    {
        TelegramMediaIntake::query()->whereKey($intake->id)->delete();
    }

    public function deleteCreatedBefore(CarbonImmutable $cutoff): int
    {
        return TelegramMediaIntake::query()->where('created_at', '<', $cutoff)->delete();
    }
}
