<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createWithRevision(Workspace $workspace, Project $project, User $author, array $payload, string $payloadMac): ProductRevision;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addRevision(Product $product, User $author, array $payload, string $payloadMac): ProductRevision;

    public function approve(Product $product, ProductRevision $revision, User $approver): ProductRevision;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Product;

    public function revision(Product $product, int $revision): ?ProductRevision;

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator;
}
