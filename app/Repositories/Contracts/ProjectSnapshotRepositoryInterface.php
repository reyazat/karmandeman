<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\User;

interface ProjectSnapshotRepositoryInterface
{
    public function nextNumber(Project $project): int;

    /**
     * @param  list<array{product_id: int, product: string, revision: int, payload_mac: string}>  $items
     */
    public function create(Project $project, User $approver, int $number, array $items, string $snapshotMac): ProjectSnapshot;

    public function latestFor(Project $project): ?ProjectSnapshot;

    /**
     * The revisions a snapshot names, from the snapshot's workspace only.
     *
     * @return list<ProductRevision>
     */
    public function revisions(ProjectSnapshot $snapshot): array;
}
