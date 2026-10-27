<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Identity;

use App\Http\Controllers\Controller;
use App\Http\Resources\Identity\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }
}
