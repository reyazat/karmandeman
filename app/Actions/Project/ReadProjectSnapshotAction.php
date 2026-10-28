<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\SnapshotIntegrityFailed;
use App\Models\ProductRevision;
use App\Models\ProjectSnapshot;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use App\Services\ProductIntake\ApprovedRevisionVerifier;
use App\Services\ProductIntake\ProjectSnapshotSigner;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * The latest approved batch, verified before anything is returned: the item
 * list against its MAC, and every revision against the MACs it was approved
 * with (ADR-018, ADR-019). A swapped or edited row is refused, never exported.
 */
final readonly class ReadProjectSnapshotAction
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ProjectSnapshotRepositoryInterface $snapshots,
        private ProjectSnapshotSigner $signer,
        private ApprovedRevisionVerifier $verifier,
    ) {}

    /**
     * @return array{ProjectSnapshot, list<ProductRevision>}
     */
    public function execute(Workspace $workspace, string $projectPublicId): array
    {
        $project = $this->projects->findPublicForWorkspace($workspace, $projectPublicId)
            ?? throw new DomainException('PROJECT_NOT_FOUND');
        // A reopened or cancelled batch has no current approval (ADR-019).
        if (! $project->state->hasApprovedSnapshot()) {
            throw new DomainException('PROJECT_NOT_APPROVED');
        }
        $project->setRelation('workspace', $workspace);
        $snapshot = $this->snapshots->latestFor($project) ?? throw new DomainException('PROJECT_NOT_APPROVED');

        try {
            if (! hash_equals($snapshot->snapshot_mac, $this->signer->sign($workspace, $project->id, $snapshot->number, $snapshot->items))) {
                throw new SnapshotIntegrityFailed($snapshot->public_id, 'item list');
            }
            $revisions = $this->snapshots->revisions($snapshot);
            if (count($revisions) !== count($snapshot->items)) {
                throw new SnapshotIntegrityFailed($snapshot->public_id, 'missing revision');
            }
            foreach ($snapshot->items as $index => $item) {
                $revision = $revisions[$index];
                if ($revision->product->public_id !== $item['product']
                    || ! hash_equals($item['payload_mac'], (string) $revision->payload_mac)) {
                    throw new SnapshotIntegrityFailed($snapshot->public_id, 'revision record');
                }
                try {
                    $this->verifier->verify($workspace, $revision->product, $revision);
                } catch (ProductIntegrityFailed $e) {
                    throw new SnapshotIntegrityFailed($snapshot->public_id, $e->reason);
                }
            }
        } catch (SnapshotIntegrityFailed $e) {
            // Ids only: the content of a tampered row is never logged.
            Log::critical('product.snapshot_integrity_failed', ['snapshot' => $e->snapshotId, 'reason' => $e->getMessage()]);

            throw $e;
        }

        return [$snapshot, $revisions];
    }
}
