<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\AttachProjectAssetAction;
use App\Actions\Project\ReadProjectAssetAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AttachProjectAssetRequest;
use App\Http\Resources\Project\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Models\Workspace;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Private batch input files (FR-025, ADR-022). */
final class ProjectAssetController extends Controller
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces) {}

    public function store(string $project, AttachProjectAssetRequest $request, AttachProjectAssetAction $attach): JsonResponse
    {
        $workspace = $this->workspace($request);
        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            [$asset, $replayed] = $attach->execute(
                $workspace, $request->user(), $request->validated('idempotency_key'), $project, $request->bytes(),
            );
        } catch (DomainException $e) {
            return $this->error($e);
        }

        return ApiResponse::success(new AssetResource($asset), $replayed || ! $asset->wasRecentlyCreated ? 200 : 201);
    }

    public function index(string $project, Request $request, ProjectRepositoryInterface $projects, AssetRepositoryInterface $assets): JsonResponse
    {
        $workspace = $this->workspace($request);
        $found = $workspace === null ? null : $projects->findPublicForWorkspace($workspace, $project);
        if ($found === null) {
            return ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404);
        }
        Gate::authorize('view', $found);

        return ApiResponse::success(AssetResource::collection($assets->paginateForProject($workspace, $found)));
    }

    /** The decrypted original, never cached and never rendered inline. */
    public function download(string $asset, Request $request, ReadProjectAssetAction $read): Response
    {
        $workspace = $this->workspace($request);
        if ($workspace === null) {
            return ApiResponse::error('ASSET_NOT_FOUND', 'فایل یافت نشد.', 404);
        }

        try {
            [$found, $bytes] = $read->execute($workspace, $request->user(), $asset);
        } catch (DomainException $e) {
            return $this->error($e);
        }

        return response($bytes, 200, [
            'Content-Type' => $found->mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.$found->public_id.'.'.self::EXTENSIONS[$found->mime].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function workspace(Request $request): ?Workspace
    {
        return $this->workspaces->currentFor($request->user());
    }

    private function error(DomainException $e): JsonResponse
    {
        return ApiResponse::fromPrivacyGate($e) ?? match ($e->getMessage()) {
            'PROJECT_NOT_FOUND' => ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404),
            'ASSET_NOT_FOUND' => ApiResponse::error('ASSET_NOT_FOUND', 'فایل یافت نشد.', 404),
            'ASSET_UNSUPPORTED' => ApiResponse::error('ASSET_UNSUPPORTED', 'فقط عکس JPEG، PNG یا WebP پذیرفته می‌شود.', 422),
            'ASSET_TOO_LARGE' => ApiResponse::error('ASSET_TOO_LARGE', 'ابعاد این عکس بیش از حد مجاز است.', 422),
            'ASSET_INTEGRITY_FAILED' => ApiResponse::error('ASSET_INTEGRITY_FAILED', 'این فایل قابل اعتماد نیست و ارسال نمی‌شود.', 500),
            'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED', 'PROJECT_NOT_ACCEPTING_INPUT' => ApiResponse::error(
                $e->getMessage(), 'نسخه یا کلید درخواست با وضعیت کنونی سازگار نیست.', 409,
            ),
            default => throw $e,
        };
    }
}
