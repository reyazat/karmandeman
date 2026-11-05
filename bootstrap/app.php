<?php

use App\Http\Middleware\AuthorizeCurrentWorkspace;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\VerifyN8nCallbackSignature;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the in-host proxy chain (Caddy, and Apache on the shared host)
        // connects from private addresses; their X-Forwarded-* headers give the
        // real scheme and client. A public peer's forwarded headers are ignored.
        $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1']);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        $middleware->alias([
            'telegram.webhook' => VerifyTelegramWebhookSecret::class,
            'n8n.callback' => VerifyN8nCallbackSignature::class,
            'active.user' => EnsureActiveUser::class,
            'workspace.authorized' => AuthorizeCurrentWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return ApiResponse::error('VALIDATION_FAILED', 'اطلاعات ورودی معتبر نیست.', 422, $exception->errors());
            }

            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => $exception->status() ?? 403,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED', 429 => 'RATE_LIMITED', default => 'REQUEST_FAILED',
            };
            $response = ApiResponse::error($code, 'امکان انجام درخواست وجود ندارد.', $status);
            if ($exception instanceof HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
