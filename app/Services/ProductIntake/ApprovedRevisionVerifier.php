<?php

declare(strict_types=1);

namespace App\Services\ProductIntake;

use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\UndecryptableValue;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Workspace;

/**
 * Checks an approved revision against its Gate A MAC and its content MAC
 * before it is exported or frozen (ADR-019). The caller logs the failure.
 */
final readonly class ApprovedRevisionVerifier
{
    public function __construct(private ProjectSnapshotSigner $signer) {}

    /** @throws ProductIntegrityFailed */
    public function verify(Workspace $workspace, Product $product, ProductRevision $revision): void
    {
        if ($revision->product_id !== $product->id || $revision->approved_at === null || $revision->approval_mac === null) {
            throw new ProductIntegrityFailed($product->public_id, 'approval record');
        }
        if (! hash_equals($revision->approval_mac, $this->signer->approvalMac($workspace, $product, $revision, $revision->approved_at))) {
            throw new ProductIntegrityFailed($product->public_id, 'approval');
        }
        try {
            $contentMac = $this->signer->payloadMac($workspace, $revision);
        } catch (UndecryptableValue) {
            throw new ProductIntegrityFailed($product->public_id, 'revision ciphertext');
        }
        if (! hash_equals((string) $revision->payload_mac, $contentMac)) {
            throw new ProductIntegrityFailed($product->public_id, 'revision content');
        }
    }
}
