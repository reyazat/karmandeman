<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProductTable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

interface ProductTableRepositoryInterface
{
    /**
     * @param  array{storage_key: string, content_mac: string, size_bytes: int, row_count: int, source: string, source_message_key: string|null}  $attributes
     */
    public function create(Project $project, User $uploader, string $publicId, array $attributes): ProductTable;

    public function findByContent(Project $project, string $contentMac): ?ProductTable;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?ProductTable;

    public function markImported(ProductTable $table, int $products, CarbonImmutable $at): void;

    public function delete(ProductTable $table): void;
}
