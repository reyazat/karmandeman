<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Artifact;
use App\Models\ArtifactFile;
use App\Models\CreditLedgerEntry;
use App\Models\ProjectSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface ArtifactRepositoryInterface
{
    /** The file of this kind made from the snapshot, row-locked. */
    public function lockForSnapshot(ProjectSnapshot $snapshot, string $kind): ?Artifact;

    public function create(ProjectSnapshot $snapshot, User $requester, string $kind, int $price): Artifact;

    /** A failed file asked for again: queued with a new price and requester. */
    public function requeue(Artifact $artifact, User $requester, int $price): void;

    /** A paid file made again, for instance after its stored copy was lost: it keeps the hold that paid, and takes no new one. */
    public function requeuePaid(Artifact $artifact, int $requesterId): void;

    /** @return list<Artifact> files still queued since before the cutoff */
    public function queuedBefore(CarbonImmutable $cutoff, int $limit): array;

    public function find(int $id): ?Artifact;

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Artifact> keyed by id, with their projects; only files of this workspace
     */
    public function forWorkspaceByIds(int $workspaceId, array $ids): Collection;

    public function lock(Artifact $artifact): Artifact;

    public function attachHold(Artifact $artifact, CreditLedgerEntry $hold): void;

    public function countAttempt(Artifact $artifact): void;

    public function markReady(Artifact $artifact, string $storageKey, string $contentMac, int $size, CarbonImmutable $at): void;

    public function markFailed(Artifact $artifact, string $errorCode): void;

    /** @return list<ArtifactFile> the pack's story images in snapshot order (ADR-053) */
    public function stories(Artifact $artifact): array;

    /**
     * Replaces the pack's story images with newly stored ones.
     *
     * @param  list<array{public_id: string, storage_key: string, content_mac: string, size_bytes: int}>  $stories  in snapshot order
     * @return list<string> storage keys of the replaced images, to delete once committed
     */
    public function replaceStories(Artifact $artifact, array $stories): array;
}
