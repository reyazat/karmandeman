<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\UndecryptableValue;
use App\Models\Asset;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Services\AI\RunAssetUrlSigner;
use App\Services\Storage\ImageMetadataStripper;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * n8n fetches one photo of a run it was given (ADR-033). The signed link is
 * the only principal: it names one run and one of its input photos, and
 * stops working when the run closes, passes its deadline or the account is
 * being erased. Every refusal is the same answer, so nothing leaks which
 * check failed. The photo leaves without metadata.
 */
final readonly class ReadRunAssetAction
{
    public const FORBIDDEN = 'RUN_ASSET_FORBIDDEN';

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private AssetRepositoryInterface $assets,
        private RunAssetUrlSigner $signer,
        private PrivateAssetStore $store,
        private ImageMetadataStripper $stripper,
    ) {}

    /**
     * @return array{Asset, string} the asset and its cleaned bytes
     *
     * @throws DomainException RUN_ASSET_FORBIDDEN, ASSET_INTEGRITY_FAILED or ASSET_METADATA_UNPARSEABLE
     */
    public function execute(string $runPublicId, string $assetPublicId, string $expires, string $signature): array
    {
        $runPublicId = strtolower($runPublicId);
        $assetPublicId = strtolower($assetPublicId);
        $run = $this->runs->findByPublicId($runPublicId);
        // Cheap state checks first: during erasure the workspace key may be gone,
        // and the MAC must never reach the key service for a run that cannot serve.
        if ($run === null
            || ! $run->status->isOpen()
            || $run->workspace->status !== 'active'
            || ! in_array($assetPublicId, $run->input_asset_ids, true)
            || ! $this->signer->valid($run, $assetPublicId, $expires, $signature, CarbonImmutable::now()->getTimestamp())) {
            throw new DomainException(self::FORBIDDEN);
        }
        $asset = $this->assets->findPublicForWorkspace($run->workspace, $assetPublicId);
        if ($asset === null || $asset->project_id !== $run->project_id) {
            throw new DomainException(self::FORBIDDEN);
        }

        try {
            $bytes = $this->store->read($run->workspace, $asset);
        } catch (UndecryptableValue) {
            Log::critical('asset.integrity_failed', ['asset' => $asset->public_id]);

            throw new DomainException('ASSET_INTEGRITY_FAILED');
        }

        return [$asset, $this->stripper->strip($asset->mime, $bytes)];
    }
}
