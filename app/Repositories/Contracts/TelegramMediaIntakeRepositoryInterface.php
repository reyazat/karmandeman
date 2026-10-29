<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

interface TelegramMediaIntakeRepositoryInterface
{
    /** Null when this message was already queued. */
    public function createOnce(Workspace $workspace, Project $project, User $user, string $fileRef, ?int $declaredSize, string $messageKey): ?TelegramMediaIntake;

    public function find(int $id): ?TelegramMediaIntake;

    public function pendingForProject(Project $project): bool;

    public function delete(TelegramMediaIntake $intake): void;

    public function deleteCreatedBefore(CarbonImmutable $cutoff): int;
}
