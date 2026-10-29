<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\UndecryptableValue;
use App\Models\Asset;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Services\Storage\PrivateAssetStore;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/** Tenant-scoped download of one private input file (ADR-022). */
final readonly class ReadProjectAssetAction
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private PrivateAssetStore $store,
    ) {}

    /**
     * @return array{Asset, string}
     */
    public function execute(Workspace $workspace, User $user, string $assetPublicId): array
    {
        $asset = $this->assets->findPublicForWorkspace($workspace, $assetPublicId)
            ?? throw new DomainException('ASSET_NOT_FOUND');
        Gate::forUser($user)->authorize('view', $asset);

        try {
            return [$asset, $this->store->read($workspace, $asset)];
        } catch (UndecryptableValue) {
            // Ids only: a tampered or missing object is never echoed.
            Log::critical('asset.integrity_failed', ['asset' => $asset->public_id]);

            throw new DomainException('ASSET_INTEGRITY_FAILED');
        }
    }
}
