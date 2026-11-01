<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\WorkflowStartUncertain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Starts an n8n workflow through its internal webhook with a signed JSON
 * body (ADR-032). n8n answers at once; the result comes back on the callback.
 * Errors name only the HTTP status, never the URL, body or secret. A start
 * whose answer was lost after sending is reported apart, as never retried.
 */
final readonly class N8nWorkflowClient
{
    public function __construct(private HttpFactory $http, private WorkflowSignature $signature) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function start(string $path, array $envelope): void
    {
        $base = rtrim((string) config('services.n8n.internal_url'), '/');
        $secret = (string) config('services.n8n.workflow_auth_token');
        if ($base === '' || $secret === '') {
            throw new RuntimeException('n8n workflow endpoint is not configured.');
        }
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();

        try {
            $response = $this->http->timeout(10)->connectTimeout(5)
                ->withHeaders([
                    WorkflowSignature::HEADER_TIMESTAMP => (string) $timestamp,
                    WorkflowSignature::HEADER_SIGNATURE => $this->signature->sign($secret, $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($base.'/'.ltrim($path, '/'));
        } catch (ConnectionException $e) {
            // The request was sent and the answer timed out or broke off: n8n may be running it (ADR-037).
            if (preg_match('/cURL error (28: Operation timed out|52|56)\b/', $e->getMessage()) === 1) {
                throw new WorkflowStartUncertain('n8n workflow start answer was lost.');
            }

            throw new RuntimeException('n8n workflow endpoint is unreachable.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('n8n workflow start failed with HTTP '.$response->status().'.');
        }
    }
}
