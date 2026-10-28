<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\AccountDeletion;

use App\Enums\AccountDeletionStatus;
use App\Models\AccountDeletion;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentAccountDeletionRepository implements AccountDeletionRepositoryInterface
{
    public function forWorkspace(Workspace $workspace): ?AccountDeletion
    {
        return AccountDeletion::query()->where('workspace_id', $workspace->id)->first();
    }

    public function create(Workspace $workspace, User $user, string $channel, CarbonImmutable $requestedAt, CarbonImmutable $deadlineAt): AccountDeletion
    {
        return AccountDeletion::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'channel' => $channel,
            'status' => AccountDeletionStatus::Requested,
            'requested_at' => $requestedAt,
            'deadline_at' => $deadlineAt,
        ]);
    }

    public function lock(AccountDeletion $deletion): AccountDeletion
    {
        return AccountDeletion::query()->whereKey($deletion->id)->lockForUpdate()->firstOrFail();
    }

    public function withStatus(AccountDeletionStatus $status, int $limit): Collection
    {
        // Fewest failures first: receipts that keep failing must not starve new ones.
        return AccountDeletion::query()->where('status', $status)
            ->orderBy('attempts')->orderBy('requested_at')->orderBy('id')->limit($limit)->get();
    }

    public function unfinishedBefore(CarbonImmutable $deadline): Collection
    {
        return AccountDeletion::query()->where('status', '!=', AccountDeletionStatus::Completed)
            ->where('deadline_at', '<=', $deadline)->orderBy('deadline_at')->get();
    }

    public function rememberTransitKey(AccountDeletion $deletion, string $keyName): void
    {
        $deletion->forceFill(['transit_key' => $keyName])->save();
    }

    public function markKeyDestroyStarted(AccountDeletion $deletion, CarbonImmutable $at): void
    {
        $deletion->forceFill(['key_destroy_started_at' => $at])->save();
    }

    public function markKeyDestroyed(AccountDeletion $deletion, CarbonImmutable $at): void
    {
        $deletion->forceFill(['key_destroyed_at' => $at])->save();
    }

    public function markLivePurged(AccountDeletion $deletion, CarbonImmutable $at): void
    {
        $deletion->forceFill([
            'workspace_id' => null,
            'user_id' => null,
            'status' => AccountDeletionStatus::AwaitingBackupExpiry,
            'live_purged_at' => $at,
            'last_error' => null,
        ])->save();
    }

    public function markCompleted(AccountDeletion $deletion, CarbonImmutable $at): void
    {
        $deletion->forceFill(['status' => AccountDeletionStatus::Completed, 'completed_at' => $at, 'transit_key' => null])->save();
    }

    public function recordFailure(AccountDeletion $deletion, string $error): void
    {
        AccountDeletion::query()->whereKey($deletion->id)->where('status', AccountDeletionStatus::Requested)
            ->increment('attempts', 1, ['last_error' => $error]);
    }
}
