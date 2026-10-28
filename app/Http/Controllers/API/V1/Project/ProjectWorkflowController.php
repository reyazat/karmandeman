<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\ApproveProjectProductsAction;
use App\Actions\Project\FinishProjectInputAction;
use App\Actions\Project\ReadProjectSnapshotAction;
use App\Exceptions\ProjectProductsNotApproved;
use App\Exceptions\SnapshotIntegrityFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectTransitionRequest;
use App\Http\Resources\Project\ProjectResource;
use App\Http\Resources\Project\ProjectSnapshotResource;
use App\Http\Responses\ApiResponse;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Batch lifecycle after manual intake (PRD §6, ADR-018). */
final class ProjectWorkflowController extends Controller
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function finishInput(string $project, ProjectTransitionRequest $request, FinishProjectInputAction $finish): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $request, $finish): array {
            [$found] = $finish->execute(
                $workspace, $request->user()->id, $request->validated('idempotency_key'), $project, $request->integer('version'),
            );

            return [new ProjectResource($found), 200];
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

    /** The approved batch as JSON records: the Step 3 batch export. */
    public function snapshot(string $project, Request $request, ReadProjectSnapshotAction $read): JsonResponse
    {
        return $this->respond($request, function (Workspace $workspace) use ($project, $read): array {
            [$snapshot, $revisions] = $read->execute($workspace, $project);

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
        } catch (SnapshotIntegrityFailed) {
            return ApiResponse::error('SNAPSHOT_INTEGRITY_FAILED', 'نسخه تأییدشده این پروژه قابل اعتماد نیست و خروجی ساخته نمی‌شود.', 500);
        } catch (DomainException $e) {
            return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404),
                'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PROJECT_STATE_CONFLICT', 'PROJECT_VERSION_CONFLICT',
                'PROJECT_HAS_NO_PRODUCTS', 'PROJECT_NOT_APPROVED' => ApiResponse::error(
                    $e->getMessage(), 'نسخه یا وضعیت پروژه با این درخواست سازگار نیست.', 409,
                ),
                default => throw $e,
            };
        }

        return ApiResponse::success($resource, $status);
    }
}
