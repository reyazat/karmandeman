<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Models\Brand;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;

final readonly class UpdateBrandProfileAction
{
    public function __construct(
        private BrandRepositoryInterface $brands,
        private MemoryItemRepositoryInterface $memory,
        private WorkspaceRepositoryInterface $workspaces,
    ) {}

    /**
     * Apply user-confirmed brand profile changes. Confirmed brand fields are
     * also stored as workspace-scoped memory items with source attribution.
     */
    public function execute(Workspace $workspace, array $attributes, ?int $confirmedBy = null): Brand
    {
        return DB::transaction(function () use ($workspace, $attributes, $confirmedBy): Brand {
            $this->workspaces->lock($workspace);
            $brand = $this->brands->ensureFor($workspace);
            $brand = $this->brands->updateProfile($brand, $attributes);

            foreach ($attributes as $key => $value) {
                $this->memory->put(
                    workspace: $workspace,
                    scope: 'workspace_profile',
                    key: "brand.{$key}",
                    value: ['value' => $value],
                    verificationStatus: 'confirmed',
                    confirmedBy: $confirmedBy,
                    sourceType: 'brand_profile',
                    sourceId: $brand->id,
                );
            }

            return $brand;
        });
    }
}
