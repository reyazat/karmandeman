<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Models\VoiceAsset;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface VoiceAssetRepositoryInterface
{
    public function findByContent(Project $project, string $contentMac): ?VoiceAsset;

    /**
     * @param  array{storage_key: string, content_mac: string, mime: string, size_bytes: int, duration_ms: int, source: string, source_message_key: string|null}  $attributes
     */
    public function create(Workspace $workspace, Project $project, User $user, string $publicId, array $attributes): VoiceAsset;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?VoiceAsset;

    /** @return list<VoiceAsset> oldest first, bounded so a large batch cannot exhaust the worker */
    public function forProject(Workspace $workspace, Project $project, int $limit): array;

    /** @return LengthAwarePaginator<int, VoiceAsset> */
    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator;
}
