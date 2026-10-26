<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\MemoryItem;

use App\Models\MemoryItem;
use App\Models\Workspace;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use Illuminate\Support\Collection;

final class EloquentMemoryItemRepository implements MemoryItemRepositoryInterface
{
    public function put(
        Workspace $workspace,
        string $scope,
        string $key,
        array $value,
        string $verificationStatus = 'confirmed',
        ?int $confirmedBy = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): MemoryItem {
        $existing = MemoryItem::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('employee_id')
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            $existing->fill([
                'value_json' => $value,
                'verification_status' => $verificationStatus,
                'confirmed_by' => $confirmedBy,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
            $existing->revision = $existing->revision + 1;
            $existing->save();

            return $existing;
        }

        return MemoryItem::create([
            'workspace_id' => $workspace->id,
            'scope' => $scope,
            'key' => $key,
            'value_json' => $value,
            'verification_status' => $verificationStatus,
            'confirmed_by' => $confirmedBy,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    public function activeFor(Workspace $workspace, ?string $scope = null): Collection
    {
        return MemoryItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->orderBy('key')
            ->get();
    }

    public function deprecate(MemoryItem $item): MemoryItem
    {
        $item->status = 'deprecated';
        $item->save();

        return $item;
    }
}
