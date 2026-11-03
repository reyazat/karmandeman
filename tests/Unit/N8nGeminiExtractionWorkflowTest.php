<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * The retired Gemini export (ADR-037), kept for history: Laravel never
 * starts or claims a run for it (ADR-045).
 */
final class N8nGeminiExtractionWorkflowTest extends N8nExtractionWorkflowTestCase
{
    protected function export(): string
    {
        return 'product-extraction.v1.json';
    }

    protected function webhookPath(): string
    {
        return (string) config('services.n8n.extraction_webhook_path');
    }

    protected function provider(): string
    {
        return 'Gemini';
    }

    protected function modelCredential(): array
    {
        return ['httpHeaderAuth' => ['id' => 'kmGeminiApiKey', 'name' => 'KM Gemini API key']];
    }

    protected function modelAddress(): array
    {
        return [
            "const GEMINI_HOST = 'https://generativelanguage.googleapis.com';",
            'url: `${GEMINI_HOST}/v1beta/models/${MODEL}:generateContent`',
        ];
    }
}
