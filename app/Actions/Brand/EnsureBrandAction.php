<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Models\Brand;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use Illuminate\Support\Facades\DB;

final readonly class EnsureBrandAction
{
    public function __construct(
        private BrandRepositoryInterface $brands,
        private LockActiveWorkspaceAction $lockActive,
    ) {}

    /**
     * Reading never writes. A missing brand is created only under the active
     * workspace row lock, so a read authorized before T0 cannot leave a new
     * row behind after erasure starts (FR-086).
     */
    public function execute(Workspace $workspace): Brand
    {
        return $this->brands->forWorkspace($workspace) ?? DB::transaction(function () use ($workspace): Brand {
            $this->lockActive->execute($workspace);

            return $this->brands->ensureFor($workspace);
        });
    }
}
