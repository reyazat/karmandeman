<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Data\Product\ProductRevisionDraft;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    /** Products still waiting for Gate A. */
    public const FILTER_NEEDS_REVIEW = 'needs_review';

    /** Review queue (FR-031): unconfirmed fields, unknown price or no image. */
    public const FILTER_INCOMPLETE = 'incomplete';

    public const FILTER_DUPLICATES = 'duplicates';

    /** @throws \DomainException PRODUCT_SKU_TAKEN */
    public function createWithRevision(Workspace $workspace, Project $project, User $author, ProductRevisionDraft $draft): ProductRevision;

    /** @throws \DomainException PRODUCT_SKU_TAKEN */
    public function addRevision(Product $product, User $author, ProductRevisionDraft $draft): ProductRevision;

    /** Gate A stamp with its MAC from ProjectSnapshotSigner::approvalMac over the same second (ADR-019). */
    public function approve(Product $product, ProductRevision $revision, User $approver, CarbonImmutable $approvedAt, string $approvalMac): ProductRevision;

    /** Reject (FR-041): revisions stay, SKUs are released. */
    public function archive(Product $product): Product;

    public function findPublicForWorkspace(Workspace $workspace, string $publicId): ?Product;

    public function revision(Product $product, int $revision): ?ProductRevision;

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateForProject(Workspace $workspace, Project $project, ?string $filter = null, int $perPage = 20): LengthAwarePaginator;

    /**
     * Products of the batch that are not archived, oldest first.
     *
     * @return Collection<int, Product>
     */
    public function activeForProject(Workspace $workspace, Project $project): Collection;

    /**
     * Server-side batch counts (FR-040); archived products count only in total and archived.
     *
     * @return array{total: int, needs_review: int, approved: int, archived: int, ready_for_approval: int, needs_confirmation: int, price_missing: int, image_missing: int, duplicate_suspected: int}
     */
    public function summaryForProject(Workspace $workspace, Project $project): array;

    /**
     * Public ids of live products in the workspace with the same name key and
     * not told apart by two SKUs (FR-034, ADR-020). A warning only.
     *
     * @return list<string>
     */
    public function suspectedDuplicatesOf(Workspace $workspace, Product $product, int $limit = 20): array;
}
