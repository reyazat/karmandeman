<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Product;

use App\Actions\Product\ApproveProductRevisionAction;
use App\Actions\Product\ArchiveProductAction;
use App\Actions\Product\CreateManualProductDraftAction;
use App\Actions\Product\ReadApprovedProductAction;
use App\Actions\Product\ReviseProductDraftAction;
use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\ProductRequiresConfirmation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ApproveProductRequest;
use App\Http\Requests\Product\ArchiveProductRequest;
use App\Http\Requests\Product\IndexProductsRequest;
use App\Http\Requests\Product\StoreProductDraftRequest;
use App\Http\Resources\Product\ProductRecordResource;
use App\Http\Resources\Product\ProductResource;
use App\Http\Resources\Product\ProductSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProductRevision;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductController extends Controller
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function index(string $project, IndexProductsRequest $request, ProjectRepositoryInterface $projects, ProductRepositoryInterface $products): JsonResponse
    {
        $workspace = $this->workspace($request);
        $found = $workspace === null ? null : $projects->findPublicForWorkspace($workspace, $project);

        return $found === null
            ? ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404)
            : ApiResponse::success(ProductResource::collection($products->paginateForProject($workspace, $found, $request->filter())));
    }

    /** Batch counts computed by the server (FR-040). */
    public function summary(string $project, Request $request, ProjectRepositoryInterface $projects, ProductRepositoryInterface $products): JsonResponse
    {
        $workspace = $this->workspace($request);
        $found = $workspace === null ? null : $projects->findPublicForWorkspace($workspace, $project);

        return $found === null
            ? ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404)
            : ApiResponse::success(new ProductSummaryResource($products->summaryForProject($workspace, $found)));
    }

    public function store(string $project, StoreProductDraftRequest $request, CreateManualProductDraftAction $create): JsonResponse
    {
        return $this->mutate($request, 201, fn (Workspace $workspace) => $create->execute(
            $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->draft(),
        ));
    }

    public function show(string $product, Request $request, ProductRepositoryInterface $products): JsonResponse
    {
        $workspace = $this->workspace($request);
        $found = $workspace === null ? null : $products->findPublicForWorkspace($workspace, $product);

        return $found === null
            ? ApiResponse::error('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404)
            : ApiResponse::success((new ProductResource($found))->withDuplicates($products->suspectedDuplicatesOf($workspace, $found)));
    }

    public function revise(string $product, StoreProductDraftRequest $request, ReviseProductDraftAction $revise): JsonResponse
    {
        return $this->mutate($request, 200, fn (Workspace $workspace) => $revise->execute(
            $workspace, $request->user(), $request->validated('idempotency_key'), $product,
            $request->integer('expected_revision'), $request->draft(),
        ));
    }

    public function approve(string $product, ApproveProductRequest $request, ApproveProductRevisionAction $approve): JsonResponse
    {
        return $this->mutate($request, 200, fn (Workspace $workspace) => $approve->execute(
            $workspace, $request->user(), $request->validated('idempotency_key'), $product, $request->integer('revision'),
        ));
    }

    /** Reject in item review (FR-041); answers with the product, not one revision. */
    public function archive(string $product, ArchiveProductRequest $request, ArchiveProductAction $archive): JsonResponse
    {
        return $this->mutate($request, 200, fn (Workspace $workspace) => $archive->execute(
            $workspace, $request->user(), $request->validated('idempotency_key'), $product,
        ), fn (ProductRevision $revision) => new ProductResource($revision->product->load('latestRevision')));
    }

    /** The approved, immutable record: the first export (Step 3 JSON), verified first (ADR-019). */
    public function approved(string $product, Request $request, ReadApprovedProductAction $read): JsonResponse
    {
        $workspace = $this->workspace($request);
        if ($workspace === null) {
            return ApiResponse::error('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404);
        }

        try {
            return ApiResponse::success(new ProductRecordResource($read->execute($workspace, $product)));
        } catch (ProductIntegrityFailed) {
            return ApiResponse::error('PRODUCT_INTEGRITY_FAILED', 'نسخه تأییدشده این محصول قابل اعتماد نیست و خروجی ساخته نمی‌شود.', 500);
        } catch (DomainException $e) {
            return match ($e->getMessage()) {
                'PRODUCT_NOT_FOUND' => ApiResponse::error('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404),
                'PRODUCT_NOT_APPROVED' => ApiResponse::error('PRODUCT_NOT_APPROVED', 'این محصول هنوز تأیید نشده است.', 409),
                default => throw $e,
            };
        }
    }

    private function workspace(Request $request): ?Workspace
    {
        return $this->workspaces->currentFor($request->user());
    }

    /**
     * @param  Closure(Workspace): array{ProductRevision, bool}  $mutation
     * @param  (Closure(ProductRevision): JsonResource)|null  $resource
     */
    private function mutate(Request $request, int $createdStatus, Closure $mutation, ?Closure $resource = null): JsonResponse
    {
        $workspace = $this->workspace($request);
        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            [$revision, $replayed] = $mutation($workspace);
        } catch (ProductRequiresConfirmation $e) {
            return ApiResponse::error($e->getMessage(), 'برخی مشخصات نیازمند تأیید هستند.', 422, ['fields' => $e->fields]);
        } catch (DomainException $e) {
            return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404),
                'PRODUCT_NOT_FOUND' => ApiResponse::error('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404),
                'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PRODUCT_REVISION_CONFLICT',
                'PRODUCT_ARCHIVED', 'PROJECT_NOT_ACCEPTING_PRODUCTS', 'PROJECT_CANCELLED' => ApiResponse::error(
                    $e->getMessage(), 'نسخه یا کلید درخواست با وضعیت کنونی سازگار نیست.', 409,
                ),
                'PRODUCT_SKU_TAKEN' => ApiResponse::error('PRODUCT_SKU_TAKEN', 'این SKU برای محصول دیگری در همین فضای کاری ثبت شده است.', 409),
                default => throw $e,
            };
        }

        return ApiResponse::success(
            $resource === null ? new ProductRecordResource($revision) : $resource($revision),
            $replayed ? 200 : $createdStatus,
        );
    }
}
