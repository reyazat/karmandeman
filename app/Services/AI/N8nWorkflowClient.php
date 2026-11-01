<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Starts an n8n workflow through its internal webhook with a signed JSON
 * body (ADR-032). n8n answers at once; the result comes back on the callback.
 * Errors name only the HTTP status, never the URL, body or secret.
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
        } catch (ConnectionException) {
            throw new RuntimeException('n8n workflow endpoint is unreachable.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('n8n workflow start failed with HTTP '.$response->status().'.');
        }
    }
}
