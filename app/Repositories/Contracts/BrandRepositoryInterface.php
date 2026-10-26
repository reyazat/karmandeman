<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Brand;
use App\Models\Workspace;

interface BrandRepositoryInterface
{
    public function ensureFor(Workspace $workspace): Brand;

    public function forWorkspace(Workspace $workspace): ?Brand;

    /**
     * Update profile fields and bump profile_version.
     */
    public function updateProfile(Brand $brand, array $attributes): Brand;
}
