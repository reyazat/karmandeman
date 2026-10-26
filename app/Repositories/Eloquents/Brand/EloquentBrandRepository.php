<?php

declare(strict_types=1);

namespace App\Repositories\Eloquents\Brand;

use App\Models\Brand;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;

final class EloquentBrandRepository implements BrandRepositoryInterface
{
    public function ensureFor(Workspace $workspace): Brand
    {
        return Brand::firstOrCreate(
            ['workspace_id' => $workspace->id],
            ['currency_display_unit' => 'toman'],
        )->refresh();
    }

    public function forWorkspace(Workspace $workspace): ?Brand
    {
        return Brand::query()->where('workspace_id', $workspace->id)->first();
    }

    public function updateProfile(Brand $brand, array $attributes): Brand
    {
        $brand->fill($attributes);
        $brand->profile_version = $brand->profile_version + 1;
        $brand->save();

        return $brand;
    }
}
