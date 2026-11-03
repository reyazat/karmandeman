<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiWorkflowRun;
use App\Services\Crypto\WorkspaceFieldCipher;

/**
 * The link n8n uses to fetch one photo (ADR-033) or one voice (ADR-047) of
 * one run. A photo and a voice link are signed in separate contexts. It expires with
 * the run's deadline and its MAC uses the workspace key, so erasure kills
 * every link of that account. Laravel's signed routes use APP_KEY instead,
 * which no erasure destroys.
 */
final readonly class RunAssetUrlSigner
{
    private const CONTEXT = 'ai_workflow_runs.asset_url';

    private const VOICE_CONTEXT = 'ai_workflow_runs.voice_url';

    public function __construct(private WorkspaceFieldCipher $cipher) {}

    public function url(AiWorkflowRun $run, string $assetPublicId): string
    {
        return $this->link($run, route('webhooks.n8n.run-assets', ['run' => $run->public_id, 'asset' => $assetPublicId], false), self::CONTEXT, $assetPublicId);
    }

    public function valid(AiWorkflowRun $run, string $assetPublicId, string $expires, string $signature, int $now): bool
    {
        return $this->check($run, self::CONTEXT, $assetPublicId, $expires, $signature, $now);
    }

    public function voiceUrl(AiWorkflowRun $run, string $voicePublicId): string
    {
        return $this->link($run, route('webhooks.n8n.run-voices', ['run' => $run->public_id, 'voice' => $voicePublicId], false), self::VOICE_CONTEXT, $voicePublicId);
    }

    public function validVoice(AiWorkflowRun $run, string $voicePublicId, string $expires, string $signature, int $now): bool
    {
        return $this->check($run, self::VOICE_CONTEXT, $voicePublicId, $expires, $signature, $now);
    }

    /** Where n8n reaches this app; the internal network address when set. */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('services.n8n.app_url') ?: config('app.url')), '/');
    }

    private function link(AiWorkflowRun $run, string $path, string $context, string $publicId): string
    {
        $expires = $run->deadline_at->getTimestamp();

        return self::baseUrl().$path.'?'.http_build_query([
            'expires' => $expires,
            'signature' => $this->signature($run, $context, $publicId, $expires),
        ]);
    }

    private function check(AiWorkflowRun $run, string $context, string $publicId, string $expires, string $signature, int $now): bool
    {
        // The link lives exactly as long as the run may; a later expiry is never accepted.
        if ($expires !== (string) $run->deadline_at->getTimestamp() || $now > (int) $expires) {
            return false;
        }

        return hash_equals($this->signature($run, $context, $publicId, (int) $expires), $signature);
    }

    private function signature(AiWorkflowRun $run, string $context, string $publicId, int $expires): string
    {
        return $this->cipher->mac($run->workspace, $context, "{$run->public_id}|{$publicId}|{$expires}");
    }
}
