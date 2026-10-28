<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Product;

use App\Data\Product\ProductRevisionDraft;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\ProductSku;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    public function createWithRevision(Workspace $workspace, Project $project, User $author, ProductRevisionDraft $draft): ProductRevision
    {
        if ($project->workspace_id !== $workspace->id) {
            throw new DomainException('INVALID_PRODUCT_PROJECT');
        }

        $product = Product::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'status' => ProductStatus::NeedsReview,
            'current_revision' => 1,
            ...$this->flags($draft),
        ]);
        $product->setRelation('workspace', $workspace);
        $this->holdSkus($product, [$draft->skuMac]);
        $this->refreshDuplicates($workspace->id, [$draft->nameMac]);

        return $this->writeRevision($product, 1, $author, $draft);
    }

    public function addRevision(Product $product, User $author, ProductRevisionDraft $draft): ProductRevision
    {
        // The approved record keeps its SKU until it is approved again or archived.
        $approvedSku = $product->approved_revision === null ? null : ProductRevision::query()
            ->where('product_id', $product->id)->where('revision', $product->approved_revision)->value('sku_mac');
        $this->holdSkus($product, [$draft->skuMac, $approvedSku]);

        $next = $product->current_revision + 1;
        $previousName = $product->name_mac;
        // An approval belongs to one revision only; the new one needs Gate A again.
        $product->forceFill([
            'current_revision' => $next, 'status' => ProductStatus::NeedsReview, ...$this->flags($draft), 'duplicate_suspected' => false,
        ])->save();
        // Both the group the product left and the one it joined change.
        $this->refreshDuplicates($product->workspace_id, [$previousName, $draft->nameMac]);

        return $this->writeRevision($product, $next, $author, $draft);
    }

    public function approve(Product $product, ProductRevision $revision, User $approver, CarbonImmutable $approvedAt, string $approvalMac): ProductRevision
    {
        if ($revision->product_id !== $product->id || $revision->approved_at !== null) {
            throw new DomainException('INVALID_PRODUCT_APPROVAL');
        }
        $this->holdSkus($product, [$revision->sku_mac]);
        $revision->forceFill(['approved_at' => $approvedAt, 'approved_by' => $approver->id, 'approval_mac' => $approvalMac])->save();
        $product->forceFill(['status' => ProductStatus::Approved, 'approved_revision' => $revision->revision])->save();

        return $revision;
    }

    public function archive(Product $product): Product
    {
        ProductSku::query()->where('workspace_id', $product->workspace_id)->where('product_id', $product->id)->delete();
        $product->forceFill(['status' => ProductStatus::Archived, 'duplicate_suspected' => false])->save();
        $this->refreshDuplicates($product->workspace_id, [$product->name_mac]);

        return $product;
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

    public function paginateForProject(Workspace $workspace, Project $project, ?string $filter = null, int $perPage = 20): LengthAwarePaginator
    {
        $page = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('project_id', $project->id)
            ->when($filter === self::FILTER_NEEDS_REVIEW, fn (Builder $query) => $query
                ->whereIn('status', [ProductStatus::DraftExtracted->value, ProductStatus::NeedsReview->value]))
            ->when($filter === self::FILTER_DUPLICATES, fn (Builder $query) => $query
                ->where('status', '!=', ProductStatus::Archived->value)->where('duplicate_suspected', true))
            ->when($filter === self::FILTER_INCOMPLETE, fn (Builder $query) => $query
                ->where('status', '!=', ProductStatus::Archived->value)
                ->where(fn (Builder $flags) => $flags->where('needs_confirmation', true)
                    ->orWhere('price_missing', true)->orWhere('image_missing', true)))
            ->with('latestRevision')
            ->orderBy('id')
            ->paginate($perPage)
            // Page links keep ?filter=, or page 2 of the review queue is the whole batch.
            ->withQueryString();
        // Encrypted payloads decrypt with this workspace's key without one lookup per row.
        foreach ($page->getCollection() as $product) {
            $product->setRelation('workspace', $workspace)->setRelation('project', $project);
            $product->latestRevision?->setRelation('workspace', $workspace)->setRelation('product', $product);
        }

        return $page;
    }

    public function activeForProject(Workspace $workspace, Project $project): Collection
    {
        $products = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('project_id', $project->id)
            ->where('status', '!=', ProductStatus::Archived->value)
            ->orderBy('id')
            ->get();
        $products->each->setRelation('workspace', $workspace);

        return $products->toBase();
    }

    public function summaryForProject(Workspace $workspace, Project $project): array
    {
        $archived = ProductStatus::Archived->value;
        $row = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('project_id', $project->id)
            ->toBase()
            ->selectRaw(
                'count(*) as total,'
                .' sum(case when status in (?, ?) then 1 else 0 end) as needs_review,'
                .' sum(case when status = ? then 1 else 0 end) as approved,'
                .' sum(case when status = ? then 1 else 0 end) as archived,'
                .' sum(case when status in (?, ?) and not needs_confirmation then 1 else 0 end) as ready_for_approval,'
                .' sum(case when status <> ? and needs_confirmation then 1 else 0 end) as needs_confirmation,'
                .' sum(case when status <> ? and price_missing then 1 else 0 end) as price_missing,'
                .' sum(case when status <> ? and image_missing then 1 else 0 end) as image_missing,'
                .' sum(case when status <> ? and duplicate_suspected then 1 else 0 end) as duplicate_suspected',
                [
                    ProductStatus::DraftExtracted->value, ProductStatus::NeedsReview->value,
                    ProductStatus::Approved->value, $archived,
                    ProductStatus::DraftExtracted->value, ProductStatus::NeedsReview->value,
                    $archived, $archived, $archived, $archived,
                ],
            )
            ->first();

        $counts = [];
        foreach (['total', 'needs_review', 'approved', 'archived', 'ready_for_approval', 'needs_confirmation', 'price_missing', 'image_missing', 'duplicate_suspected'] as $key) {
            $counts[$key] = (int) ($row->{$key} ?? 0);
        }

        return $counts;
    }

    public function suspectedDuplicatesOf(Workspace $workspace, Product $product, int $limit = 20): array
    {
        if ($product->name_mac === null || $product->status === ProductStatus::Archived) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = Product::query()
            ->where('workspace_id', $workspace->id)
            ->where('name_mac', $product->name_mac)
            ->whereKeyNot($product->id)
            ->where('status', '!=', ProductStatus::Archived->value)
            // Two different SKUs are two products the seller told apart.
            ->when($product->has_sku, fn (Builder $query) => $query->where('has_sku', false))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id')
            ->all();

        return $ids;
    }

    /**
     * @return array{needs_confirmation: bool, price_missing: bool, image_missing: bool, name_mac: string|null, has_sku: bool}
     */
    private function flags(ProductRevisionDraft $draft): array
    {
        return [
            'needs_confirmation' => $draft->needsConfirmation,
            'price_missing' => $draft->priceMissing,
            'image_missing' => $draft->imageMissing,
            'name_mac' => $draft->nameMac,
            'has_sku' => $draft->skuMac !== null,
        ];
    }

    /**
     * Recompute the near-duplicate warning (FR-034, ADR-020) of every live
     * product sharing these names. The workspace row lock held by every
     * product mutation serializes it, so the stored flag stays exact.
     *
     * @param  list<string|null>  $macs
     */
    private function refreshDuplicates(int $workspaceId, array $macs): void
    {
        $macs = array_values(array_unique(array_filter($macs, fn (?string $mac): bool => $mac !== null)));
        if ($macs === []) {
            return;
        }

        $group = Product::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('name_mac', $macs)
            ->where('status', '!=', ProductStatus::Archived->value)
            ->toBase()
            ->get(['id', 'name_mac', 'has_sku', 'duplicate_suspected']);
        // One pass of counts per name: P is suspect when another product shares
        // the name and P or that product has no SKU.
        $counts = [];
        foreach ($group as $row) {
            $counts[$row->name_mac] ??= ['total' => 0, 'without_sku' => 0];
            $counts[$row->name_mac]['total']++;
            $counts[$row->name_mac]['without_sku'] += (bool) $row->has_sku ? 0 : 1;
        }
        $flag = $clear = [];
        foreach ($group as $row) {
            ['total' => $total, 'without_sku' => $withoutSku] = $counts[$row->name_mac];
            $suspected = $total > 1 && (! (bool) $row->has_sku || $withoutSku > 0);
            if ($suspected && ! (bool) $row->duplicate_suspected) {
                $flag[] = $row->id;
            } elseif (! $suspected && (bool) $row->duplicate_suspected) {
                $clear[] = $row->id;
            }
        }
        // updated_at moves too: the flag of a product the user did not touch changed.
        if ($flag !== []) {
            Product::query()->whereKey($flag)->update(['duplicate_suspected' => true, 'updated_at' => now()]);
        }
        if ($clear !== []) {
            Product::query()->whereKey($clear)->update(['duplicate_suspected' => false, 'updated_at' => now()]);
        }
    }

    /**
     * Reserve exactly these SKUs for the product. The workspace row lock held
     * by every product mutation serializes the check; the unique index is the
     * second guard (ADR-017).
     *
     * @param  list<string|null>  $macs
     */
    private function holdSkus(Product $product, array $macs): void
    {
        $macs = array_values(array_unique(array_filter($macs, fn (?string $mac): bool => $mac !== null)));
        $taken = $macs !== [] && ProductSku::query()
            ->where('workspace_id', $product->workspace_id)
            ->whereIn('sku_mac', $macs)
            ->where('product_id', '!=', $product->id)
            ->exists();
        if ($taken) {
            throw new DomainException('PRODUCT_SKU_TAKEN');
        }

        ProductSku::query()->where('workspace_id', $product->workspace_id)->where('product_id', $product->id)->delete();
        foreach ($macs as $mac) {
            ProductSku::create(['workspace_id' => $product->workspace_id, 'product_id' => $product->id, 'sku_mac' => $mac]);
        }
    }

    private function writeRevision(Product $product, int $number, User $author, ProductRevisionDraft $draft): ProductRevision
    {
        // workspace_id first: the encrypted payload needs its workspace key.
        $revision = new ProductRevision(['workspace_id' => $product->workspace_id]);
        $revision->setRelation('workspace', $product->getRelation('workspace'));
        $revision->fill([
            'product_id' => $product->id,
            'revision' => $number,
            'payload' => $draft->payload,
            'payload_mac' => $draft->payloadMac,
            'sku_mac' => $draft->skuMac,
            'created_by' => $author->id,
            'created_at' => now(),
        ])->save();
        $revision->setRelation('product', $product);

        return $revision;
    }
}
