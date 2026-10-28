<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\AccountDeletionStatus;
use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface AccountDeletionRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?AccountDeletion;

    public function create(Workspace $workspace, User $user, string $channel, CarbonImmutable $requestedAt, CarbonImmutable $deadlineAt): AccountDeletion;

    /** Lock the row and return its current state. */
    public function lock(AccountDeletion $deletion): AccountDeletion;

    /** @return Collection<int, AccountDeletion> oldest T0 first */
    public function withStatus(AccountDeletionStatus $status, int $limit): Collection;

    /** @return Collection<int, AccountDeletion> unfinished requests whose deadline is before the given time */
    public function unfinishedBefore(CarbonImmutable $deadline): Collection;

    public function rememberTransitKey(AccountDeletion $deletion, string $keyName): void;

    public function markKeyDestroyStarted(AccountDeletion $deletion, CarbonImmutable $at): void;

    public function markKeyDestroyed(AccountDeletion $deletion, CarbonImmutable $at): void;

    /** Detach from the purged workspace and user and wait for backup expiry. */
    public function markLivePurged(AccountDeletion $deletion, CarbonImmutable $at): void;

    public function markCompleted(AccountDeletion $deletion, CarbonImmutable $at): void;

    /** Counts a failed pass; a receipt another pass already moved on is left untouched. */
    public function recordFailure(AccountDeletion $deletion, string $error): void;
}
