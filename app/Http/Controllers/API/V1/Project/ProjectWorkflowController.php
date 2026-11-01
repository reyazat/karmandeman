<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\ApproveProjectProductsAction;
use App\Actions\Project\CancelProjectAction;
use App\Actions\Project\CreateExtractionQuoteAction;
use App\Actions\Project\FinishProjectInputAction;
use App\Actions\Project\ReadProjectSnapshotAction;
use App\Actions\Project\ReopenProjectAction;
use App\Enums\ProjectState;
use App\Exceptions\ProductIntegrityFailed;
use App\Exceptions\ProjectProductsNotApproved;
use App\Exceptions\SnapshotIntegrityFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\FinishProjectInputRequest;
use App\Http\Requests\Project\ProjectTransitionRequest;
use App\Http\Resources\Project\ProjectResource;
use App\Http\Resources\Project\ProjectSnapshotResource;
use App\Http\Resources\Wallet\CreditQuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Batch lifecycle after manual intake (PRD §6, ADR-018, ADR-019). */
final class ProjectWorkflowController extends Controller
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function finishInput(string $project, FinishProjectInputRequest $request, FinishProjectInputAction $finish): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $finish): array {
            [$found] = $finish->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->integer('version'), $request->quoteId(),
            );

            // Extraction runs asynchronously on n8n (ADR-032).
            return [new ProjectResource($found), $found->state === ProjectState::Extracting ? 202 : 200];
        });
    }

    /** The price of the extraction run the batch's new photos need (FR-072, ADR-039). */
    public function extractionQuote(string $project, ProjectTransitionRequest $request, CreateExtractionQuoteAction $quote): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $quote): array {
            [$created, $replayed, $available] = $quote->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->integer('version'),
            );

            return [(new CreditQuoteResource($created))->withAvailable($available), $replayed ? 200 : 201];
        });
    }

    public function approveProducts(string $project, ProjectTransitionRequest $request, ApproveProjectProductsAction $approve): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $approve): array {
            [$snapshot, $replayed] = $approve->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->integer('version'),
            );

            return [new ProjectSnapshotResource($snapshot), $replayed ? 200 : 201];
        });
    }

    public function reopen(string $project, ProjectTransitionRequest $request, ReopenProjectAction $reopen): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $reopen): array {
            [$found] = $reopen->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->integer('version'),
            );

            return [new ProjectResource($found), 200];
        });
    }

    public function cancel(string $project, ProjectTransitionRequest $request, CancelProjectAction $cancel): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $cancel): array {
            [$found] = $cancel->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->integer('version'),
            );

            return [new ProjectResource($found), 200];
        });
    }

    /** The approved batch as JSON records: the Step 3 batch export. */
    public function snapshot(string $project, Request $request, ReadProjectSnapshotAction $read): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $read): array {
            [$snapshot, $revisions] = $read->execute($workspace, $request->user(), $project);

            return [new ProjectSnapshotResource($snapshot, $revisions), 200];
        });
    }

    /**
     * @param  Closure(Workspace): array{JsonResource, int}  $handler
     */
    private function respond(Request $request, Closure $handler): JsonResponse
    {
        $workspace = $this->workspaces->currentFor($request->user());
        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            [$resource, $status] = $handler($workspace);
        } catch (ProjectProductsNotApproved $e) {
            return ApiResponse::error($e->getMessage(), 'همه محصولات این پروژه هنوز تأیید نشده‌اند.', 422, ['products' => $e->products]);
        } catch (ProductIntegrityFailed) {
            return ApiResponse::error('PRODUCT_INTEGRITY_FAILED', 'نسخه تأییدشده یکی از محصولات قابل اعتماد نیست و پروژه تأیید نشد.', 500);
        } catch (SnapshotIntegrityFailed) {
            return ApiResponse::error('SNAPSHOT_INTEGRITY_FAILED', 'نسخه تأییدشده این پروژه قابل اعتماد نیست و خروجی ساخته نمی‌شود.', 500);
        } catch (DomainException $e) {
            return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404),
                'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PROJECT_STATE_CONFLICT', 'PROJECT_VERSION_CONFLICT',
                'PROJECT_HAS_NO_PRODUCTS', 'PROJECT_NOT_APPROVED' => ApiResponse::error(
                    $e->getMessage(), 'نسخه یا وضعیت پروژه با این درخواست سازگار نیست.', 409,
                ),
                'EXTRACTION_NOT_NEEDED' => ApiResponse::error(
                    'EXTRACTION_NOT_NEEDED', 'این پروژه عکس تازه‌ای برای استخراج ندارد؛ پایان ورودی بدون پیش‌فاکتور انجام می‌شود.', 409,
                ),
                'EXTRACTION_QUOTE_REQUIRED' => ApiResponse::error(
                    'EXTRACTION_QUOTE_REQUIRED', 'استخراج عکس‌ها هزینه دارد؛ اول پیش‌فاکتور بگیر و با شناسه آن ادامه بده.', 409,
                ),
                'QUOTE_NOT_FOUND' => ApiResponse::error('QUOTE_NOT_FOUND', 'پیش‌فاکتور یافت نشد.', 404),
                'QUOTE_ALREADY_USED', 'QUOTE_EXPIRED', 'QUOTE_STALE' => ApiResponse::error(
                    $e->getMessage(), 'این پیش‌فاکتور دیگر معتبر نیست؛ پیش‌فاکتور تازه بگیر.', 409,
                ),
                'INSUFFICIENT_CREDITS' => ApiResponse::error('INSUFFICIENT_CREDITS', 'اعتبار کافی برای این پیش‌فاکتور نداری.', 409),
                'EXTRACTION_TOO_MANY_PHOTOS' => ApiResponse::error(
                    'EXTRACTION_TOO_MANY_PHOTOS', 'تعداد عکس‌های این پروژه برای یک استخراج بیش از حد مجاز است.', 422,
                    ['max_photos' => (int) config('karmandeman.ai.max_assets_per_run')],
                ),
                'EXTRACTION_PHOTOS_TOO_LARGE' => ApiResponse::error(
                    'EXTRACTION_PHOTOS_TOO_LARGE', 'حجم عکس‌های این پروژه برای یک استخراج بیش از حد مجاز است.', 422,
                    ['max_bytes' => (int) config('karmandeman.ai.max_input_bytes_per_run')],
                ),
                default => throw $e,
            };
        }

        return ApiResponse::success($resource, $status);
    }
}
