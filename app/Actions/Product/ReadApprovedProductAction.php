<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Exceptions\ProductIntegrityFailed;
use App\Models\ProductRevision;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\ApprovedRevisionVerifier;
use DomainException;
use Illuminate\Support\Facades\Log;

/** The approved record of one product, verified before it is returned (ADR-019). */
final readonly class ReadApprovedProductAction
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private ApprovedRevisionVerifier $verifier,
    ) {}

    public function execute(Workspace $workspace, string $productPublicId): ProductRevision
    {
        $product = $this->products->findPublicForWorkspace($workspace, $productPublicId)
            ?? throw new DomainException('PRODUCT_NOT_FOUND');
        $revision = ($product->approved_revision === null ? null : $this->products->revision($product, $product->approved_revision))
            ?? throw new DomainException('PRODUCT_NOT_APPROVED');

        try {
            $this->verifier->verify($workspace, $product, $revision);
        } catch (ProductIntegrityFailed $e) {
            // Ids only: the content of a tampered row is never logged.
            Log::critical('product.integrity_failed', ['product' => $e->productId, 'reason' => $e->getMessage()]);

            throw $e;
        }

        return $revision;
    }
}
