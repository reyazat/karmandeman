<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Identity;

use App\Actions\Identity\IssueTelegramTokenAction;
use App\Data\Telegram\TelegramUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\TelegramVerifyRequest;
use App\Http\Resources\Identity\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Telegram\TelegramInitDataValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TelegramAuthController extends Controller
{
    public function store(
        TelegramVerifyRequest $request,
        TelegramInitDataValidator $validator,
        IssueTelegramTokenAction $issueToken,
    ): JsonResponse {
        $fields = $validator->validate($request->string('initData')->toString());

        $user = $fields !== null ? json_decode((string) ($fields['user'] ?? ''), true) : null;

        try {
            $telegramUser = is_array($user) ? TelegramUserData::fromArray($user) : null;
        } catch (InvalidArgumentException) {
            $telegramUser = null;
        }

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

            throw $e;
        }

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($account),
        ]);
    }
}
