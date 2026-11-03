<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\VoiceAsset;

use App\Models\Project;
use App\Models\User;
use App\Models\VoiceAsset;
use App\Models\Workspace;
use App\Repositories\Contracts\VoiceAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentVoiceAssetRepository implements VoiceAssetRepositoryInterface
{
    public function findByContent(Project $project, string $contentMac): ?VoiceAsset
    {
        return VoiceAsset::query()->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)->where('content_mac', $contentMac)->first();
    }

    public function create(Workspace $workspace, Project $project, User $user, string $publicId, array $attributes): VoiceAsset
    {
        $asset = new VoiceAsset([
            ...$attributes,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
        ]);
        $asset->public_id = $publicId;
        $asset->save();

        return $asset;
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?VoiceAsset
    {
        return VoiceAsset::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator
    {
        return VoiceAsset::query()->where('workspace_id', $workspace->id)->where('project_id', $project->id)
            ->orderBy('id')->paginate($perPage);
    }
}
