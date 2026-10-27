<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Brand;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateBrandProfileAction
{
    public function __construct(
        private BrandRepositoryInterface $brands,
        private MemoryItemRepositoryInterface $memory,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * Apply user-confirmed brand profile changes. Confirmed brand fields are
     * also stored as workspace-scoped memory items with source attribution.
     */
    public function execute(Workspace $workspace, array $attributes, ?int $confirmedBy = null, ?int $expectedVersion = null): Brand
    {
        return DB::transaction(function () use ($workspace, $attributes, $confirmedBy, $expectedVersion): Brand {
            $this->lockActive->execute($workspace);
            $brand = $this->brands->ensureFor($workspace);
            if ($expectedVersion !== null && $brand->profile_version !== $expectedVersion) {
                throw new DomainException('BRAND_VERSION_CONFLICT');
            }
            $brand = $this->brands->updateProfile($brand, $attributes);

            foreach ($attributes as $key => $value) {
                if ($value === null) {
                    $item = $this->memory->activeFor($workspace, 'workspace_profile')
                        ->firstWhere('key', "brand.{$key}");
                    if ($item !== null) {
                        $this->memory->deprecate($item, $confirmedBy);
                    }

                    continue;
                }

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
