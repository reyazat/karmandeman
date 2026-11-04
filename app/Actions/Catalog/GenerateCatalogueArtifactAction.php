<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Project\ReadProjectSnapshotAction;
use App\Actions\Wallet\CaptureCreditsAction;
use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Exceptions\SnapshotIntegrityFailed;
use App\Jobs\Export\DeliverCatalogueArtifact;
use App\Models\Artifact;
use App\Models\ProductRevision;
use App\Repositories\Contracts\ArtifactRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Export\CataloguePdfRenderer;
use App\Services\Export\CataloguePhotoEncoder;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Export worker side of the catalogue PDF (ADR-052): renders exactly the
 * snapshot the file was asked for, after the same verification as every
 * export, stores it encrypted and spends the hold in one transaction with
 * "ready". A file that cannot be made from that snapshot fails without charge.
 * Transport and storage errors are thrown, so the job retries.
 */
final readonly class GenerateCatalogueArtifactAction
{
    public function __construct(
        private ArtifactRepositoryInterface $artifacts,
        private UserRepositoryInterface $users,
        private ProjectRepositoryInterface $projects,
        private ReadProjectSnapshotAction $read,
        private AssetRepositoryInterface $assets,
        private BrandRepositoryInterface $brands,
        private PrivateAssetStore $store,
        private CataloguePhotoEncoder $photos,
        private CataloguePdfRenderer $renderer,
        private LockActiveWorkspaceAction $lockActive,
        private WalletRepositoryInterface $wallets,
        private CaptureCreditsAction $capture,
        private FailCatalogueArtifactAction $fail,
        private ProjectSnapshotRepositoryInterface $snapshots,
    ) {}

    public function execute(int $artifactId): void
    {
        $artifact = $this->artifacts->find($artifactId);
        if ($artifact === null || $artifact->status !== Artifact::STATUS_QUEUED) {
            return;
        }
        $workspace = $artifact->workspace;
        $user = $artifact->requested_by === null ? null : $this->users->find($artifact->requested_by);
        $project = $this->projects->findForWorkspace($workspace, $artifact->project_id);
        // Erasure started, or the requester or batch is gone: make nothing and release the hold (FR-086).
        if ($workspace->status !== 'active' || $user === null || $project === null) {
            $this->fail->execute($artifact, 'REQUEST_GONE');

            return;
        }
        $this->artifacts->countAttempt($artifact);

        try {
            [$snapshot, $revisions] = $this->read->execute($workspace, $user, $project->public_id);
        } catch (SnapshotIntegrityFailed) {
            $this->fail->execute($artifact, 'SNAPSHOT_INTEGRITY_FAILED');

            return;
        } catch (AuthorizationException|DomainException) {
            $this->fail->execute($artifact, 'SNAPSHOT_NOT_CURRENT');

            return;
        }
        // Reopened and approved again since the press: that edit is a new file, asked for anew.
        if ($snapshot->id !== $artifact->project_snapshot_id) {
            $this->fail->execute($artifact, 'SNAPSHOT_NOT_CURRENT');

            return;
        }

        $products = [];
        foreach ($revisions as $revision) {
            $products[] = ['payload' => $revision->payload, 'photo' => $this->mainPhoto($artifact, $revision)];
        }
        $bytes = $this->renderer->render(
            (string) ($this->brands->forWorkspace($workspace)->name ?? ''), $project->name, $snapshot->number, $products,
        );

        $storageKey = $this->store->put($workspace, $artifact->public_id, $bytes, PrivateAssetStore::KIND_ARTIFACT);
        $mac = $this->store->contentMac($workspace, $bytes, PrivateAssetStore::KIND_ARTIFACT);
        $stale = DB::transaction(function () use ($workspace, $artifact, $project, $user, $storageKey, $mac, $bytes): bool {
            // Whatever undoes "ready" also removes the object, so no unpaid file stays stored.
            DB::afterRollBack(fn () => rescue(fn () => $this->store->delete($storageKey)));
            $this->lockActive->execute($workspace);
            $locked = $this->artifacts->lock($artifact);
            // A file made once and lost from storage is made again on the first payment (ADR-052).
            $paid = $locked->generated_at !== null;
            if ($locked->status !== Artifact::STATUS_QUEUED || ($locked->hold_entry_id === null && ! $paid)) {
                $this->store->delete($storageKey);

                return false;
            }
            // Cancelled or reopened while the file was rendered: never charge for a batch no longer approved.
            $current = $this->projects->findForWorkspace($workspace, $project->id);
            $latest = $current !== null && $current->state->hasApprovedSnapshot() ? $this->snapshots->latestFor($current) : null;
            if ($latest?->id !== $locked->project_snapshot_id) {
                $this->store->delete($storageKey);

                return true;
            }
            $this->artifacts->markReady($locked, $storageKey, $mac, strlen($bytes), CarbonImmutable::now());
            if (! $paid) {
                $hold = $this->wallets->findEntryById((int) $locked->hold_entry_id) ?? throw new DomainException('ARTIFACT_HOLD_MISSING');
                // The owner's rule: only a file that was made is paid for (ADR-052).
                $this->capture->execute($hold, Artifact::KIND_CATALOGUE_PDF);
            }
            DeliverCatalogueArtifact::dispatch($locked->id, $user->id)->onConnection('telegram')->onQueue('telegram')->beforeCommit();

            return false;
        });
        if ($stale) {
            $this->fail->execute($artifact, 'SNAPSHOT_NOT_CURRENT');
        }
    }

    /** The product's first photo as a metadata-free JPEG, or null for a product without one. */
    private function mainPhoto(Artifact $artifact, ProductRevision $revision): ?string
    {
        $id = $revision->payload['image_asset_ids'][0] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $asset = $this->assets->findPublicForWorkspace($artifact->workspace, $id)
            ?? throw new DomainException('CATALOGUE_PHOTO_MISSING');

        return $this->photos->jpeg($this->store->read($artifact->workspace, $asset));
    }
}
