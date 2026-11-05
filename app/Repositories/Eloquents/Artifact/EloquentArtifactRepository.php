<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Artifact;

use App\Models\Artifact;
use App\Models\ArtifactFile;
use App\Models\CreditLedgerEntry;
use App\Models\ProjectSnapshot;
use App\Models\User;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentArtifactRepository implements ArtifactRepositoryInterface
{
    public function lockForSnapshot(ProjectSnapshot $snapshot, string $kind): ?Artifact
    {
        return Artifact::query()->where('workspace_id', $snapshot->workspace_id)
            ->where('project_snapshot_id', $snapshot->id)->where('kind', $kind)->lockForUpdate()->first();
    }

    public function create(ProjectSnapshot $snapshot, User $requester, string $kind, int $price): Artifact
    {
        return Artifact::query()->create([
            'workspace_id' => $snapshot->workspace_id,
            'project_id' => $snapshot->project_id,
            'project_snapshot_id' => $snapshot->id,
            'requested_by' => $requester->id,
            'kind' => $kind,
            'status' => Artifact::STATUS_QUEUED,
            'price_credits' => $price,
        ]);
    }

    public function requeue(Artifact $artifact, User $requester, int $price): void
    {
        $artifact->forceFill([
            'status' => Artifact::STATUS_QUEUED, 'requested_by' => $requester->id, 'price_credits' => $price,
            'hold_entry_id' => null, 'error_code' => null,
        ])->save();
    }

    public function requeuePaid(Artifact $artifact, int $requesterId): void
    {
        $artifact->forceFill([
            // The hold that paid for it stays: the remake is not paid again.
            'status' => Artifact::STATUS_QUEUED, 'requested_by' => $requesterId, 'error_code' => null,
        ])->save();
    }

    public function queuedBefore(CarbonImmutable $cutoff, int $limit): array
    {
        return Artifact::query()->where('status', Artifact::STATUS_QUEUED)->where('updated_at', '<', $cutoff)
            ->orderBy('id')->limit($limit)->get()->all();
    }

    public function find(int $id): ?Artifact
    {
        return Artifact::query()->find($id);
    }

    public function forWorkspaceByIds(int $workspaceId, array $ids): Collection
    {
        return $ids === [] ? collect() : Artifact::query()->with('project')->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)->get()->keyBy('id')->toBase();
    }

    public function lock(Artifact $artifact): Artifact
    {
        return Artifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
    }

    public function attachHold(Artifact $artifact, CreditLedgerEntry $hold): void
    {
        $artifact->forceFill(['hold_entry_id' => $hold->id])->save();
    }

    public function countAttempt(Artifact $artifact): void
    {
        $artifact->forceFill(['attempts' => $artifact->attempts + 1])->save();
    }

    public function markReady(Artifact $artifact, string $storageKey, string $contentMac, int $size, CarbonImmutable $at): void
    {
        $artifact->forceFill([
            'status' => Artifact::STATUS_READY, 'storage_key' => $storageKey, 'content_mac' => $contentMac,
            'size_bytes' => $size, 'generated_at' => $at, 'error_code' => null,
        ])->save();
    }

    public function markFailed(Artifact $artifact, string $errorCode): void
    {
        $artifact->forceFill(['status' => Artifact::STATUS_FAILED, 'error_code' => $errorCode])->save();
    }

    public function stories(Artifact $artifact): array
    {
        return ArtifactFile::query()->where('workspace_id', $artifact->workspace_id)->where('artifact_id', $artifact->id)
            ->orderBy('position')->get()->all();
    }

    public function replaceStories(Artifact $artifact, array $stories): array
    {
        $old = fn () => ArtifactFile::query()->where('workspace_id', $artifact->workspace_id)->where('artifact_id', $artifact->id);
        $keys = $old()->pluck('storage_key')->all();
        $old()->delete();
        foreach ($stories as $index => $story) {
            ArtifactFile::query()->create($story + [
                'workspace_id' => $artifact->workspace_id, 'artifact_id' => $artifact->id, 'position' => $index + 1,
            ]);
        }

        return $keys;
    }
}
