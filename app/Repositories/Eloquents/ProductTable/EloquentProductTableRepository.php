<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\ProductTable;

use App\Models\ProductTable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductTableRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentProductTableRepository implements ProductTableRepositoryInterface
{
    public function create(Project $project, User $uploader, string $publicId, array $attributes): ProductTable
    {
        $table = new ProductTable([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $uploader->id,
            'status' => ProductTable::STATUS_PENDING,
            ...$attributes,
        ]);
        $table->public_id = $publicId;
        $table->save();

        return $table;
    }

    public function findByContent(Project $project, string $contentMac): ?ProductTable
    {
        return ProductTable::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->where('content_mac', $contentMac)
            ->first();
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?ProductTable
    {
        return ProductTable::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    public function markImported(ProductTable $table, int $products, CarbonImmutable $at): void
    {
        $table->forceFill(['status' => ProductTable::STATUS_IMPORTED, 'product_count' => $products, 'imported_at' => $at])->save();
    }

    public function delete(ProductTable $table): void
    {
        ProductTable::query()->where('workspace_id', $table->workspace_id)->whereKey($table->id)->delete();
    }
}
