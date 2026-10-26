<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\MemoryItem;
use App\Models\Workspace;
use Illuminate\Support\Collection;

interface MemoryItemRepositoryInterface
{
    /**
     * Store or update an active memory item for (workspace, scope, key).
     * A new revision is written rather than mutating silently.
     */
    public function put(
        Workspace $workspace,
        string $scope,
        string $key,
        array $value,
        string $verificationStatus = 'confirmed',
        ?int $confirmedBy = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): MemoryItem;

    /**
     * @return Collection<int, MemoryItem>
     */
    public function activeFor(Workspace $workspace, ?string $scope = null): Collection;

    public function deprecate(MemoryItem $item): MemoryItem;
}
