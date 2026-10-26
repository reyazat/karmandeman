<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhookSecret
{
    private const HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.webhook_secret');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header(self::HEADER, ''))) {
            abort(403);
        }

        return $next($request);
    }
}
