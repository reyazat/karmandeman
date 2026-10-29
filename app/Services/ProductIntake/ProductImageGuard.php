<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Exceptions\ProductAssetsRejected;
use App\Models\Project;
use App\Repositories\Contracts\AssetRepositoryInterface;

/**
 * A product may only point at photos of its own batch (ADR-023): never another
 * batch, another workspace or an id that does not exist. Runs under the
 * workspace lock with the rest of the mutation.
 */
final readonly class ProductImageGuard
{
    public function __construct(private AssetRepositoryInterface $assets) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public function check(?Project $project, array $draft): void
    {
        $ids = ProductDraftNormalizer::imageAssetIds($draft);
        if ($ids === []) {
            return;
        }
        $found = $project === null ? [] : $this->assets->publicIdsInProject($project, $ids);
        $rejected = [];
        foreach ($ids as $index => $id) {
            if (! in_array($id, $found, true)) {
                $rejected[] = "image_asset_ids.{$index}";
            }
        }
        if ($rejected !== []) {
            throw new ProductAssetsRejected($rejected);
        }
    }
}
