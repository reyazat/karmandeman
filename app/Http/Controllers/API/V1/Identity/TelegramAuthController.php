<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Identity;

use App\Actions\Identity\IssueTelegramTokenAction;
use App\Actions\Privacy\FindContainedTelegramAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\TelegramVerifyRequest;
use App\Http\Resources\Identity\UserResource;
use App\Http\Resources\Privacy\AccountDeletionResource;
use App\Http\Responses\ApiResponse;
use App\Services\Telegram\TelegramInitDataValidator;
use DomainException;
use Illuminate\Http\JsonResponse;

final class TelegramAuthController extends Controller
{
    public function store(
        TelegramVerifyRequest $request,
        TelegramInitDataValidator $validator,
        IssueTelegramTokenAction $issueToken,
        FindContainedTelegramAccountAction $containedAccounts,
    ): JsonResponse {
        $telegramUser = $validator->verifiedUser($request->string('initData')->toString());
        if ($telegramUser === null) {
            return ApiResponse::error(
                'TELEGRAM_INIT_DATA_INVALID',
                'اعتبارسنجی داده تلگرام ناموفق بود.',
                401,
            );
        }

        try {
            [$account, $token] = $issueToken->execute(
                $telegramUser,
                $request->string('initData')->toString(),
                $request->validated('idempotency_key'),
            );
        } catch (DomainException $e) {
            if (in_array($e->getMessage(), ['IDEMPOTENCY_KEY_CONFLICT', 'AUTH_REPLAY_EXPIRED'], true)) {
                return ApiResponse::error($e->getMessage(), 'کلید درخواست با ورود قبلی سازگار نیست.', 409);
            }
            if ($e->getMessage() === 'ACCOUNT_DELETION_PENDING') {
                $deletion = $containedAccounts->execute($telegramUser->id)?->deletion;

                return ApiResponse::error('ACCOUNT_DELETION_PENDING', 'درخواست حذف این حساب ثبت شده و در حال رسیدگی است.', 409,
                    $deletion === null ? [] : ['deletion' => (new AccountDeletionResource($deletion))->resolve($request)]);
            }
            if ($response = ApiResponse::fromPrivacyGate($e)) {
                return $response;
            }

            throw $e;
        }

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($account),
        ]);
    }
}
