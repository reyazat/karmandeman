<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Asset;
use App\Models\Product;
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

    /**
     * The 1-based upload position in the batch of each given photo, so the bot
     * can name photos as the seller sent them (ADR-034). Unknown ids are left out.
     *
     * @param  list<string>  $publicIds
     * @return array<string, int>
     */
    public function positionsInProject(Project $project, array $publicIds): array;

    /**
     * Assets of this project with these public ids, in the given order; unknown ids are skipped.
     *
     * @param  list<string>  $publicIds
     * @return list<Asset>
     */
    public function inProjectByPublicIds(Project $project, array $publicIds): array;

    /**
     * Photos of the batch no bot /product has claimed yet, oldest first (ADR-025).
     *
     * @return list<Asset>
     */
    public function unclaimedInProject(Project $project, int $limit): array;

    /**
     * @param  list<Asset>  $assets
     */
    public function claim(array $assets, Product $product): void;

    public function releaseClaims(Product $product): void;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Asset;

    /**
     * @return LengthAwarePaginator<int, Asset>
     */
    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator;
}
