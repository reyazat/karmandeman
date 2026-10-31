<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\Project;

/**
 * The n8n side of a product extraction run (ADR-032): whether runs may
 * start, the versioned input envelope and the workflow call. Laravel keeps
 * all state; the envelope names the batch photos by public id and a signed
 * link to each, never their bytes.
 */
final readonly class AIWorkflowService
{
    public const INPUT_SCHEMA_VERSION = 'product-extraction.input.v1';

    public function __construct(private N8nWorkflowClient $client, private RunAssetUrlSigner $urls) {}

    /**
     * Off unless configured, and always off in production: no AI provider is
     * approved and Step 5 does not yet reserve credit before a run.
     */
    public function extractionEnabled(): bool
    {
        return (bool) config('karmandeman.ai.extraction_enabled') && ! app()->isProduction();
    }

    /**
     * @param  list<Asset>  $assets  the run's input photos, in the run's order
     * @return array<string, mixed>
     */
    public function extractionEnvelope(AiWorkflowRun $run, Project $project, array $assets): array
    {
        return [
            'schema_version' => self::INPUT_SCHEMA_VERSION,
            'workflow_version' => $run->workflow_version,
            'run_id' => $run->public_id,
            'project_id' => $project->public_id,
            'attempt' => $run->dispatch_attempts,
            'locale' => 'fa-IR',
            'assets' => array_map(fn (Asset $asset): array => [
                'id' => $asset->public_id,
                'mime' => $asset->mime,
                'size_bytes' => $asset->size_bytes,
                'width' => $asset->width,
                'height' => $asset->height,
                // Signed for this run only; the bytes come without metadata (ADR-033).
                'download_url' => $this->urls->url($run, $asset->public_id),
            ], $assets),
            'limits' => [
                'max_candidates' => (int) config('karmandeman.ai.max_candidates_per_run'),
                'max_model_requests' => (int) config('karmandeman.ai.max_model_requests'),
                'max_input_tokens' => (int) config('karmandeman.ai.max_input_tokens'),
                'max_output_tokens' => (int) config('karmandeman.ai.max_output_tokens'),
            ],
            'deadline_at' => $run->deadline_at->toIso8601String(),
            'callback_url' => (string) (config('services.n8n.callback_url') ?: RunAssetUrlSigner::baseUrl().route('webhooks.n8n.runs', [], false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function startExtraction(array $envelope): void
    {
        $this->client->start((string) config('services.n8n.extraction_webhook_path'), $envelope);
    }

    /**
     * Reported usage over any cap of the run (PRD §11, FR-064).
     *
     * @param  array{model_requests: int, input_tokens: int, output_tokens: int, provider: string, model: string}  $usage
     */
    public function usageOverCap(array $usage): bool
    {
        return $usage['model_requests'] > (int) config('karmandeman.ai.max_model_requests')
            || $usage['input_tokens'] > (int) config('karmandeman.ai.max_input_tokens')
            || $usage['output_tokens'] > (int) config('karmandeman.ai.max_output_tokens');
    }
}
