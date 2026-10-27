<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\CreateApiProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\CreateProjectRequest;
use App\Http\Resources\Project\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectController extends Controller
{
    public function show(
        string $project,
        Request $request,
        WorkspaceRepositoryInterface $workspaces,
        ProjectRepositoryInterface $projects,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());
        $found = $workspace === null ? null : $projects->findPublicForWorkspace($workspace, $project);

        return $found === null
            ? ApiResponse::error('PROJECT_NOT_FOUND', 'پروژه یافت نشد.', 404)
            : ApiResponse::success(new ProjectResource($found));
    }

    public function index(
        Request $request,
        WorkspaceRepositoryInterface $workspaces,
        ProjectRepositoryInterface $projects,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        return ApiResponse::success(
            ProjectResource::collection($projects->paginateFor($workspace))
        );
    }

    public function store(
        CreateProjectRequest $request,
        WorkspaceRepositoryInterface $workspaces,
        CreateApiProjectAction $createProject,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            [$project, $replayed] = $createProject->execute(
                $workspace,
                $request->user()->id,
                $request->validated('idempotency_key'),
                $request->string('name')->toString() ?: null,
            );
        } catch (DomainException $e) {
            if ($e->getMessage() === 'IDEMPOTENCY_KEY_CONFLICT') {
                return ApiResponse::error('IDEMPOTENCY_KEY_CONFLICT', 'این کلید قبلاً برای درخواست دیگری استفاده شده است.', 409);
            }
            if ($response = ApiResponse::fromPrivacyGate($e)) {
                return $response;
            }

            if ($e->getMessage() === 'NO_ACTIVE_EMPLOYEE') {
                return ApiResponse::error('NO_ACTIVE_EMPLOYEE', 'کارمند فعالی برای این فضای کاری یافت نشد.', 409);
            }

            throw $e;
        }

        return ApiResponse::success(new ProjectResource($project), $replayed ? 200 : 201);
    }
}
