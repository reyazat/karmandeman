<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AssetRepositoryInterface
{
    /**
     * @param  array{storage_key: string, content_mac: string, mime: string, size_bytes: int, width: int, height: int, source: string, source_message_key: string|null}  $attributes
     */
    public function create(Workspace $workspace, Project $project, User $uploader, string $publicId, array $attributes): Asset;

    public function findByContent(Project $project, string $contentMac): ?Asset;

    /**
     * The given public ids that name assets of this project.
     *
     * @param  list<string>  $publicIds
     * @return list<string>
     */
    public function publicIdsInProject(Project $project, array $publicIds): array;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Asset;

    /**
     * @return LengthAwarePaginator<int, Asset>
     */
    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator;
}
