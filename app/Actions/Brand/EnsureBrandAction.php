<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Models\Brand;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;

final readonly class EnsureBrandAction
{
    public function __construct(
        private BrandRepositoryInterface $brands,
    ) {}

    public function execute(Workspace $workspace): Brand
    {
        return $this->brands->ensureFor($workspace);
    }
}
