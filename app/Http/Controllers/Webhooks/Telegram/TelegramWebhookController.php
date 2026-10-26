<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Telegram;

use App\Actions\Identity\HandleTelegramUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\TelegramWebhookRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramWebhookController extends Controller
{
    public function __invoke(TelegramWebhookRequest $request, HandleTelegramUpdateAction $handle): JsonResponse
    {
        try {
            return ApiResponse::success(['status' => $handle->execute($request->validated())]);
        } catch (Throwable $exception) {
            Log::error('telegram.update_failed', ['exception_type' => $exception::class]);

            return ApiResponse::error('TELEGRAM_UPDATE_FAILED', 'پردازش پیام موقتاً ناموفق بود.', 503);
        }
    }
}
