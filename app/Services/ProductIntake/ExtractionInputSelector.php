<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use DomainException;

/**
 * The photos an extraction run of the batch would read (ADR-032), shared by
 * the quote and the run start so both price and send the same set (ADR-039).
 */
final readonly class ExtractionInputSelector
{
    /** Far above any batch the upload throttle allows; the run cap is checked after filtering. */
    private const PHOTO_SCAN_LIMIT = 1000;

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private ProductRepositoryInterface $products,
        private AssetRepositoryInterface $assets,
    ) {}

    /**
     * Photos no product uses yet and no earlier run has read: not claimed by a
     * bot product, not cited by an API product's current revision, and not
     * an input of a run that succeeded, even one that found nothing. Refused
     * here, not by n8n, when they cannot fit one run, so /done never loops.
     *
     * @return list<Asset>
     *
     * @throws DomainException EXTRACTION_TOO_MANY_PHOTOS or EXTRACTION_PHOTOS_TOO_LARGE
     */
    public function photos(Workspace $workspace, Project $project): array
    {
        $used = [...$this->runs->extractedAssetIds($project), ...$this->products->citedImageIds($workspace, $project)];
        $photos = array_values(array_filter(
            $this->assets->unclaimedInProject($project, self::PHOTO_SCAN_LIMIT),
            fn (Asset $asset): bool => ! in_array($asset->public_id, $used, true),
        ));

        if (count($photos) > (int) config('karmandeman.ai.max_assets_per_run')) {
            throw new DomainException('EXTRACTION_TOO_MANY_PHOTOS');
        }
        if (array_sum(array_map(fn (Asset $asset): int => (int) $asset->size_bytes, $photos)) > (int) config('karmandeman.ai.max_input_bytes_per_run')) {
            throw new DomainException('EXTRACTION_PHOTOS_TOO_LARGE');
        }

        return $photos;
    }
}
