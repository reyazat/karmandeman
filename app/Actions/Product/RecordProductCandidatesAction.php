<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\ProductRevisionBuilder;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Stores the candidates of one accepted extraction run as product drafts
 * (FR-030, ADR-032). Every value is only suggested, so none passes Gate A
 * unconfirmed. Each photo is claimed by the first candidate citing it; a
 * photo no candidate cites stays unclaimed for the review queue (FR-031).
 * Runs inside the callback's transaction, under the workspace lock.
 */
final readonly class RecordProductCandidatesAction
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private AssetRepositoryInterface $assets,
        private ProductRevisionBuilder $builder,
    ) {}

    /**
     * @param  list<array{draft: array<string, mixed>, source_asset_ids: list<string>}>  $candidates
     * @param  list<string>  $inputAssetIds
     * @return int the number of products created
     */
    public function execute(Workspace $workspace, Project $project, User $author, array $candidates, array $inputAssetIds): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RecordProductCandidatesAction requires an open transaction.');
        }
        $unclaimed = [];
        foreach ($this->assets->unclaimedInProject($project, count($inputAssetIds)) as $asset) {
            $unclaimed[$asset->public_id] = $asset;
        }

        $created = 0;
        foreach ($candidates as $candidate) {
            $draft = $candidate['draft'];
            try {
                $product = $this->store($workspace, $project, $author, $draft, $candidate['source_asset_ids']);
            } catch (DomainException $e) {
                if ($e->getMessage() !== 'PRODUCT_SKU_TAKEN') {
                    throw $e;
                }
                // A suggested SKU never takes over one already held in the workspace.
                unset($draft['sku']);
                $product = $this->store($workspace, $project, $author, $draft, $candidate['source_asset_ids']);
            }
            $claims = array_values(array_filter(array_map(
                fn (string $id): ?Asset => $unclaimed[$id] ?? null, $candidate['source_asset_ids'],
            )));
            $this->assets->claim($claims, $product);
            foreach ($claims as $asset) {
                unset($unclaimed[$asset->public_id]);
            }
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $sources
     */
    private function store(Workspace $workspace, Project $project, User $author, array $draft, array $sources): Product
    {
        // A savepoint: a refused SKU leaves no half-written product behind.
        return DB::transaction(fn () => $this->products
            ->createWithRevision($workspace, $project, $author, $this->builder->build($workspace, $project, $draft, $sources))
            ->product);
    }
}
