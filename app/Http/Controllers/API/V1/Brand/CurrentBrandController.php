<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Brand;

use App\Actions\Brand\EnsureBrandAction;
use App\Actions\Brand\UpdateApiBrandAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brand\UpdateBrandProfileRequest;
use App\Http\Resources\Brand\BrandResource;
use App\Http\Responses\ApiResponse;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentBrandController extends Controller
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

        try {
            return ApiResponse::success(new BrandResource($ensureBrand->execute($workspace)));
        } catch (DomainException $e) {
            // Erasure started after the request was authorized (FR-086).
            return ApiResponse::fromPrivacyGate($e) ?? throw $e;
        }
    }

    public function update(
        UpdateBrandProfileRequest $request,
        WorkspaceRepositoryInterface $workspaces,
        UpdateApiBrandAction $updateBrand,
    ): JsonResponse {
        $workspace = $workspaces->currentFor($request->user());

        if ($workspace === null) {
            return ApiResponse::error('WORKSPACE_NOT_FOUND', 'فضای کاری یافت نشد.', 404);
        }

        try {
            [$brand] = $updateBrand->execute(
                $workspace,
                $request->user()->id,
                $request->validated('idempotency_key'),
                $request->integer('expected_version'),
                $request->brandAttributes(),
            );
        } catch (DomainException $e) {
            if (in_array($e->getMessage(), ['BRAND_VERSION_CONFLICT', 'IDEMPOTENCY_KEY_CONFLICT', 'IDEMPOTENCY_KEY_EXPIRED'], true)) {
                return ApiResponse::error($e->getMessage(), 'نسخه یا کلید درخواست با وضعیت کنونی سازگار نیست.', 409);
            }
            if ($response = ApiResponse::fromPrivacyGate($e)) {
                return $response;
            }

            throw $e;
        }

        return ApiResponse::success(new BrandResource($brand));
    }
}
