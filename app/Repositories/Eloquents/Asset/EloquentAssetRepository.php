<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Asset;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentAssetRepository implements AssetRepositoryInterface
{
    public function create(Workspace $workspace, Project $project, User $uploader, string $publicId, array $attributes): Asset
    {
        $asset = new Asset([
            ...$attributes,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $uploader->id,
        ]);
        // The id is fixed before the upload: it is part of the ciphertext's AAD.
        $asset->public_id = $publicId;
        $asset->save();

        return $asset;
    }

    public function findByContent(Project $project, string $contentMac): ?Asset
    {
        return Asset::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->where('content_mac', $contentMac)
            ->first();
    }

    public function publicIdsInProject(Project $project, array $publicIds): array
    {
        /** @var list<string> $ids */
        $ids = Asset::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->whereIn('public_id', $publicIds)
            ->pluck('public_id')
            ->all();

        return $ids;
    }

    public function unclaimedInProject(Project $project, int $limit): array
    {
        return Asset::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->whereNull('claimed_by_product_id')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function claim(array $assets, Product $product): void
    {
        if ($assets !== []) {
            Asset::query()
                ->where('workspace_id', $product->workspace_id)
                ->whereKey(array_map(fn (Asset $asset): int => $asset->id, $assets))
                ->update(['claimed_by_product_id' => $product->id]);
        }
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Asset
    {
        return Asset::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator
    {
        return Asset::query()
            ->where('workspace_id', $workspace->id)
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->paginate($perPage);
    }
}
