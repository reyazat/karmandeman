<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Brand;

use App\Actions\Brand\EnsureBrandAction;
use App\Actions\Brand\UpdateBrandProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brand\UpdateBrandProfileRequest;
use App\Http\Resources\Brand\BrandResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentBrandController extends Controller
{
    public function show(
        Request $request,
        WorkspaceRepositoryInterface $workspaces,
        EnsureBrandAction $ensureBrand,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        return ApiResponse::success(new BrandResource($ensureBrand->execute($workspace)));
    }

    public function update(
        UpdateBrandProfileRequest $request,
        WorkspaceRepositoryInterface $workspaces,
        UpdateBrandProfileAction $updateBrand,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        $brand = $updateBrand->execute(
            $workspace,
            $request->brandAttributes(),
            confirmedBy: $request->user()->id,
        );

        return ApiResponse::success(new BrandResource($brand));
    }
}
