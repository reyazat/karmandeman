<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Shared API envelope helpers. Pure formatting only.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof AnonymousResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            return $data->additional(['meta' => self::meta($meta)])->response()->setStatusCode($status);
        }

        return response()->json([
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ]),
            'meta' => self::meta(),
        ], $status);
    }

    /** Envelope for the shared consent/erasure gates, or null for other domain errors. */
    public static function fromPrivacyGate(\DomainException $exception): ?JsonResponse
    {
        return match ($exception->getMessage()) {
            'POLICY_CONSENT_REQUIRED' => self::error('POLICY_CONSENT_REQUIRED', 'پیش از این کار، پذیرش نسخه جاری شرایط و سیاست داده لازم است.', 403),
            'ACCOUNT_DELETION_PENDING' => self::error('ACCOUNT_DELETION_PENDING', 'درخواست حذف این حساب ثبت شده و تغییر تازه پذیرفته نمی‌شود.', 409),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function meta(array $extra = []): array
    {
        return $extra + [
            'request_id' => (string) Str::uuid(),
            'api_version' => 'v1',
        ];
    }
}
