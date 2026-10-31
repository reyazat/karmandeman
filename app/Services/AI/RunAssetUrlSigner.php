<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiWorkflowRun;
use App\Services\Crypto\WorkspaceFieldCipher;

/**
 * The link n8n uses to fetch one photo of one run (ADR-033). It expires with
 * the run's deadline and its MAC uses the workspace key, so erasure kills
 * every link of that account. Laravel's signed routes use APP_KEY instead,
 * which no erasure destroys.
 */
final readonly class RunAssetUrlSigner
{
    private const CONTEXT = 'ai_workflow_runs.asset_url';

    public function __construct(private WorkspaceFieldCipher $cipher) {}

    public function url(AiWorkflowRun $run, string $assetPublicId): string
    {
        $expires = $run->deadline_at->getTimestamp();
        $path = route('webhooks.n8n.run-assets', ['run' => $run->public_id, 'asset' => $assetPublicId], false);

        return self::baseUrl().$path.'?'.http_build_query([
            'expires' => $expires,
            'signature' => $this->signature($run, $assetPublicId, $expires),
        ]);
    }

    public function valid(AiWorkflowRun $run, string $assetPublicId, string $expires, string $signature, int $now): bool
    {
        // The link lives exactly as long as the run may; a later expiry is never accepted.
        if ($expires !== (string) $run->deadline_at->getTimestamp() || $now > (int) $expires) {
            return false;
        }

        return hash_equals($this->signature($run, $assetPublicId, (int) $expires), $signature);
    }

    /** Where n8n reaches this app; the internal network address when set. */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('services.n8n.app_url') ?: config('app.url')), '/');
    }

    private function signature(AiWorkflowRun $run, string $assetPublicId, int $expires): string
    {
        return $this->cipher->mac($run->workspace, self::CONTEXT, "{$run->public_id}|{$assetPublicId}|{$expires}");
    }
}
