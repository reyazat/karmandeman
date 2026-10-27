<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks\Telegram;

use App\Actions\Identity\HandleTelegramUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\TelegramWebhookRequest;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TelegramWebhookController extends Controller
{
    public function __invoke(TelegramWebhookRequest $request, HandleTelegramUpdateAction $handle): JsonResponse
    {
        try {
            return ApiResponse::success(['status' => $handle->execute($request->validated())]);
        } catch (AuthorizationException|DomainException $exception) {
            // Deterministic refusals roll back and must not make Telegram retry the update forever.
            Log::warning('telegram.update_rejected', ['exception_type' => $exception::class]);

            return ApiResponse::success(['status' => 'rejected']);
        } catch (HttpExceptionInterface $exception) {
            Log::warning('telegram.update_rejected', ['exception_type' => $exception::class, 'status' => $exception->getStatusCode()]);

            return $exception->getStatusCode() < 500
                ? ApiResponse::success(['status' => 'rejected'])
                : ApiResponse::error('TELEGRAM_UPDATE_FAILED', 'پردازش پیام موقتاً ناموفق بود.', 503);
        } catch (Throwable $exception) {
            Log::error('telegram.update_failed', ['exception_type' => $exception::class]);

            return ApiResponse::error('TELEGRAM_UPDATE_FAILED', 'پردازش پیام موقتاً ناموفق بود.', 503);
        }
    }
}
