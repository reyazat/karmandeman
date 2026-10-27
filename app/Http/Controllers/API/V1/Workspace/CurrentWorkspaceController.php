<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Resources\Workspace\WorkspaceResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        WorkspaceRepositoryInterface $workspaces,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        return ApiResponse::success(new WorkspaceResource($workspace->load('wallet')));
    }
}
