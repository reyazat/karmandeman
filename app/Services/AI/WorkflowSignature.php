<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * HMAC-SHA256 over "{timestamp}.{raw body}", sent as `v1=<hex>` (ADR-032).
 * Used both ways with two different secrets. The timestamp bounds replay;
 * the run state makes a replay inside the window a no-op.
 */
final class WorkflowSignature
{
    public const HEADER_SIGNATURE = 'X-KM-Signature';

    public const HEADER_TIMESTAMP = 'X-KM-Timestamp';

    public function sign(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function verify(#[\SensitiveParameter] string $secret, string $timestamp, string $body, string $signature, int $now, int $tolerance): bool
    {
        if ($secret === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals($this->sign($secret, (int) $timestamp, $body), $signature);
    }
}
