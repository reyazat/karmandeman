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
use App\Services\Export\CatalogueStoryRenderer;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export worker side of the catalogue pack (ADR-052, ADR-053): renders the
 * PDF and one story image per product from exactly the snapshot the pack was
 * asked for, after the same verification as every export, stores them
 * encrypted and spends the hold in one transaction with "ready". A file that cannot be made from that snapshot fails without charge.
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
        private CatalogueStoryRenderer $stories,
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

        $brand = (string) ($this->brands->forWorkspace($workspace)->name ?? '');
        $products = [];
        $stories = [];
        foreach ($revisions as $revision) {
            $photo = $this->mainPhoto($artifact, $revision);
            $products[] = ['payload' => $revision->payload, 'photo' => $photo];
            $stories[] = $this->stories->render($brand, $project->name, $revision->payload, $photo);
        }
        $bytes = $this->renderer->render($brand, $project->name, $snapshot->number, $products);

        // Every object is written before the transaction; whatever does not end "ready" removes them all.
        $stored = [];
        try {
            $storageKey = $stored[] = $this->store->put($workspace, $artifact->public_id, $bytes, PrivateAssetStore::KIND_ARTIFACT);
            $files = [];
            foreach ($stories as $story) {
                $publicId = Str::uuid()->toString();
                $files[] = [
                    'public_id' => $publicId,
                    'storage_key' => $stored[] = $this->store->put($workspace, $publicId, $story, PrivateAssetStore::KIND_ARTIFACT),
                    'content_mac' => $this->store->contentMac($workspace, $story, PrivateAssetStore::KIND_ARTIFACT),
                    'size_bytes' => strlen($story),
                ];
            }
        } catch (Throwable $e) {
            $this->remove($stored);

            throw $e;
        }
        $removeStored = fn () => $this->remove($stored);
        $mac = $this->store->contentMac($workspace, $bytes, PrivateAssetStore::KIND_ARTIFACT);
        $stale = DB::transaction(function () use ($workspace, $artifact, $project, $user, $storageKey, $mac, $bytes, $files, $removeStored): bool {
            // Whatever undoes "ready" also removes the objects, so no unpaid file stays stored.
            DB::afterRollBack($removeStored);
            $this->lockActive->execute($workspace);
            $locked = $this->artifacts->lock($artifact);
            // A pack made once and lost from storage is made again on the first payment (ADR-052).
            $paid = $locked->generated_at !== null;
            if ($locked->status !== Artifact::STATUS_QUEUED || ($locked->hold_entry_id === null && ! $paid)) {
                $removeStored();

                return false;
            }
            // Cancelled or reopened while the file was rendered: never charge for a batch no longer approved.
            $current = $this->projects->findForWorkspace($workspace, $project->id);
            $latest = $current !== null && $current->state->hasApprovedSnapshot() ? $this->snapshots->latestFor($current) : null;
            if ($latest?->id !== $locked->project_snapshot_id) {
                $removeStored();

                return true;
            }
            // A remade pack replaces the objects of the one before it.
            $replaced = $this->artifacts->replaceStories($locked, $files);
            if ($locked->storage_key !== null) {
                $replaced[] = $locked->storage_key;
            }
            $this->artifacts->markReady($locked, $storageKey, $mac, strlen($bytes), CarbonImmutable::now());
            if (! $paid) {
                $hold = $this->wallets->findEntryById((int) $locked->hold_entry_id) ?? throw new DomainException('ARTIFACT_HOLD_MISSING');
                // The owner's rule: only a file that was made is paid for (ADR-052).
                $this->capture->execute($hold, Artifact::KIND_CATALOGUE_PDF);
            }
            DeliverCatalogueArtifact::dispatch($locked->id, $user->id)->onConnection('telegram')->onQueue('telegram')->beforeCommit();
            DB::afterCommit(fn () => $this->remove($replaced));

            return false;
        });
        if ($stale) {
            $this->fail->execute($artifact, 'SNAPSHOT_NOT_CURRENT');
        }
    }

    /** @param  list<string>  $keys  stored objects to delete; a failed delete leaves an orphan the erasure prefix still covers */
    private function remove(array $keys): void
    {
        foreach ($keys as $key) {
            rescue(fn () => $this->store->delete($key));
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
