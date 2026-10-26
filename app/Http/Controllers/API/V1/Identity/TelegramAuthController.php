<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Identity;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\TelegramVerifyRequest;
use App\Http\Resources\Identity\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Telegram\TelegramInitDataValidator;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TelegramAuthController extends Controller
{
    public function store(
        TelegramVerifyRequest $request,
        TelegramInitDataValidator $validator,
        RegisterTelegramUserAction $register,
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

        $account = $register->execute($telegramUser);
        $token = $account->createToken('telegram-webapp', ['*'], now()->addHours(8))->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($account),
        ]);
    }
}
