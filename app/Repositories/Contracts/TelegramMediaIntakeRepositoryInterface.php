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
    /**
     * Null when this message was already queued.
     *
     * @param  array{0: int, 1: int}|null  $target  product id and revision of the card a photo replies to (ADR-041)
     * @param  string|null  $sellerText  the photo's caption, already encrypted (ADR-050)
     */
    public function createOnce(Workspace $workspace, Project $project, User $user, string $fileRef, ?int $declaredSize, string $messageKey, string $kind = TelegramMediaIntake::KIND_PHOTO, ?array $target = null, ?string $sellerText = null): ?TelegramMediaIntake;

    public function find(int $id): ?TelegramMediaIntake;

    public function pendingForProject(Project $project): bool;

    public function pendingForMessage(Workspace $workspace, string $messageKey): bool;

    public function delete(TelegramMediaIntake $intake): void;

    public function deleteCreatedBefore(CarbonImmutable $cutoff): int;
}
