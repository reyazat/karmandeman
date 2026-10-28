<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Models\ProductRevision;
use App\Models\Workspace;
use App\Services\Crypto\WorkspaceFieldCipher;

/**
 * MACs that bind an approved batch to exact revision content (FR-043,
 * ADR-018). Both use the workspace key, so they die with it.
 */
final readonly class ProjectSnapshotSigner
{
    public function __construct(private WorkspaceFieldCipher $cipher) {}

    /**
     * @param  list<array{product_id: int, product: string, revision: int, payload_mac: string}>  $items
     */
    public function sign(Workspace $workspace, int $projectId, int $number, array $items): string
    {
        return $this->cipher->mac($workspace, 'project_snapshots.items', json_encode([$projectId, $number, $items], JSON_THROW_ON_ERROR));
    }

    /** MAC of the decrypted payload, computed as when the revision was written. */
    public function payloadMac(Workspace $workspace, ProductRevision $revision): string
    {
        return $this->cipher->mac($workspace, 'product_revisions.payload', json_encode($revision->payload, JSON_THROW_ON_ERROR));
    }
}
