<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Project;

use App\Actions\Project\CreateProjectAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\CreateProjectRequest;
use App\Http\Resources\Project\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
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
        CreateProjectAction $createProject,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            $project = $createProject->execute($workspace, $request->string('name')->toString() ?: null);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 'کارمند فعالی برای این فضای کاری یافت نشد.', 409);
        }

        return ApiResponse::success(new ProjectResource($project), 201);
    }
}
