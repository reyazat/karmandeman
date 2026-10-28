<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\ProjectSnapshot;

use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\User;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;

final class EloquentProjectSnapshotRepository implements ProjectSnapshotRepositoryInterface
{
    public function nextNumber(Project $project): int
    {
        return (int) ProjectSnapshot::query()->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)->max('number') + 1;
    }

    public function create(Project $project, User $approver, int $number, array $items, string $snapshotMac): ProjectSnapshot
    {
        $snapshot = ProjectSnapshot::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'number' => $number,
            'items' => $items,
            'snapshot_mac' => $snapshotMac,
            'approved_by' => $approver->id,
            'created_at' => now(),
        ]);
        $snapshot->setRelation('project', $project);

        return $snapshot;
    }

    public function latestFor(Project $project): ?ProjectSnapshot
    {
        $snapshot = ProjectSnapshot::query()->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)->orderByDesc('number')->first();
        $snapshot?->setRelation('project', $project);

        return $snapshot;
    }

    public function revisions(ProjectSnapshot $snapshot): array
    {
        $productIds = array_column($snapshot->items, 'product_id');
        $products = Product::query()->where('workspace_id', $snapshot->workspace_id)
            ->whereIn('id', $productIds)->get()->keyBy('id');
        $workspace = $snapshot->project->workspace;

        $found = [];
        foreach (ProductRevision::query()->where('workspace_id', $snapshot->workspace_id)
            ->whereIn('product_id', $productIds)->get() as $revision) {
            $found["{$revision->product_id}:{$revision->revision}"] = $revision;
        }

        $revisions = [];
        foreach ($snapshot->items as $item) {
            $revision = $found["{$item['product_id']}:{$item['revision']}"] ?? null;
            $product = $products->get($item['product_id']);
            if ($revision === null || $product === null) {
                continue;
            }
            // Encrypted payloads decrypt with this workspace's key without one lookup per row.
            $product->setRelation('workspace', $workspace);
            $revisions[] = $revision->setRelation('workspace', $workspace)->setRelation('product', $product);
        }

        return $revisions;
    }
}
