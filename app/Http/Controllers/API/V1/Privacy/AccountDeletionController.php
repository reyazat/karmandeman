<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Privacy;

use App\Actions\Privacy\RequestReauthenticatedAccountDeletionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\RequestAccountDeletionRequest;
use App\Http\Resources\Privacy\AccountDeletionResource;
use App\Http\Responses\ApiResponse;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Http\JsonResponse;

final class AccountDeletionController extends Controller
{
    public function store(
        RequestAccountDeletionRequest $request,
        TelegramInitDataValidator $validator,
        RequestReauthenticatedAccountDeletionAction $requestDeletion,
    ): JsonResponse {
        $reauthenticated = $validator->verifiedUser($request->string('initData')->toString());
        if ($reauthenticated === null) {
            return ApiResponse::error('REAUTHENTICATION_REQUIRED', 'برای حذف، Mini App را دوباره باز کنید و ظرف پنج دقیقه تأیید کنید.', 401);
        }

        [$deletion, $existed] = $requestDeletion->execute($request->user(), $reauthenticated);

        return ApiResponse::success(new AccountDeletionResource($deletion), $existed ? 200 : 201);
    }
}
