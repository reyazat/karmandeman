<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Workspace;
use App\Services\Crypto\WorkspaceFieldCipher;
use DateTimeInterface;

/**
 * MACs that bind an approval to exact revision content: one product (Gate A,
 * ADR-019) or a whole batch (FR-043, ADR-018). All use the workspace key, so
 * they die with it.
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

    /**
     * Gate A: this product, under this public id and in this batch, approved
     * this revision with this content at this second.
     */
    public function approvalMac(Workspace $workspace, Product $product, ProductRevision $revision, DateTimeInterface $approvedAt): string
    {
        return $this->cipher->mac($workspace, 'product_revisions.approval', json_encode([
            $product->id, $product->public_id, $product->project_id, $revision->revision,
            (string) $revision->payload_mac, $approvedAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }
}
