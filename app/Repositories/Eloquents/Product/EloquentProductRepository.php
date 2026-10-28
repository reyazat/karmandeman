<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Product;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    public function createWithRevision(Workspace $workspace, Project $project, User $author, array $payload, string $payloadMac): ProductRevision
    {
        if ($project->workspace_id !== $workspace->id) {
            throw new DomainException('INVALID_PRODUCT_PROJECT');
        }

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'status' => ProductStatus::NeedsReview,
            'current_revision' => 1,
        ]);
        $product->setRelation('workspace', $workspace);

        return $this->writeRevision($product, 1, $author, $payload, $payloadMac);
    }

    public function addRevision(Product $product, User $author, array $payload, string $payloadMac): ProductRevision
    {
        $next = $product->current_revision + 1;
        // An approval belongs to one revision only; the new one needs Gate A again.
        $product->forceFill(['current_revision' => $next, 'status' => ProductStatus::NeedsReview])->save();

        return $this->writeRevision($product, $next, $author, $payload, $payloadMac);
    }

    public function approve(Product $product, ProductRevision $revision, User $approver): ProductRevision
    {
        if ($revision->product_id !== $product->id || $revision->approved_at !== null) {
            throw new DomainException('INVALID_PRODUCT_APPROVAL');
        }
        $revision->forceFill(['approved_at' => now(), 'approved_by' => $approver->id])->save();
        $product->forceFill(['status' => ProductStatus::Approved, 'approved_revision' => $revision->revision])->save();

        return $revision;
    }

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Product
    {
        $product = Product::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
        $product?->setRelation('workspace', $workspace);

        return $product;
    }

    public function revision(Product $product, int $revision): ?ProductRevision
    {
        $found = ProductRevision::query()
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->where('revision', $revision)
            ->first();
        if ($found !== null && $product->relationLoaded('workspace')) {
            $found->setRelation('workspace', $product->getRelation('workspace'));
        }
        $found?->setRelation('product', $product);

        return $found;
    }

    public function paginateForProject(Workspace $workspace, Project $project, int $perPage = 20): LengthAwarePaginator
    {
        $page = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('project_id', $project->id)
            ->with('latestRevision')
            ->orderBy('id')
            ->paginate($perPage);
        // Encrypted payloads decrypt with this workspace's key without one lookup per row.
        foreach ($page->getCollection() as $product) {
            $product->setRelation('workspace', $workspace)->setRelation('project', $project);
            $product->latestRevision?->setRelation('workspace', $workspace)->setRelation('product', $product);
        }

        return $page;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeRevision(Product $product, int $number, User $author, array $payload, string $payloadMac): ProductRevision
    {
        // workspace_id first: the encrypted payload needs its workspace key.
        $revision = new ProductRevision(['workspace_id' => $product->workspace_id]);
        $revision->setRelation('workspace', $product->getRelation('workspace'));
        $revision->fill([
            'product_id' => $product->id,
            'revision' => $number,
            'payload' => $payload,
            'payload_mac' => $payloadMac,
            'created_by' => $author->id,
            'created_at' => now(),
        ])->save();
        $revision->setRelation('product', $product);

        return $revision;
    }
}
