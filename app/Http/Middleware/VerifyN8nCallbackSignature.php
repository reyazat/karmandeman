<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AI\WorkflowSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * n8n run callbacks must carry a fresh HMAC of the raw body under the
 * callback secret (ADR-032). An unset secret refuses everything.
 */
final readonly class VerifyN8nCallbackSignature
{
    public function __construct(private WorkflowSignature $signature) {}

    public function handle(Request $request, Closure $next): Response
    {
        $valid = $this->signature->verify(
            (string) config('services.n8n.callback_secret'),
            (string) $request->header(WorkflowSignature::HEADER_TIMESTAMP, ''),
            $request->getContent(),
            (string) $request->header(WorkflowSignature::HEADER_SIGNATURE, ''),
            now()->getTimestamp(),
            (int) config('karmandeman.ai.callback_tolerance_seconds'),
        );
        if (! $valid) {
            abort(401);
        }

        return $next($request);
    }
}
