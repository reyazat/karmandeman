<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\AIWorkflowService;

/** The OpenAI export Laravel starts extraction runs for (ADR-045, ADR-046). */
final class N8nOpenAiExtractionWorkflowTest extends N8nExtractionWorkflowTestCase
{
    protected function export(): string
    {
        return 'product-extraction.openai.v1.json';
    }

    protected function webhookPath(): string
    {
        return AIWorkflowService::OPENAI_WEBHOOK_PATH;
    }

    protected function provider(): string
    {
        return 'OpenAI';
    }

    protected function modelCredential(): array
    {
        return ['httpHeaderAuth' => ['id' => 'kmOpenAiApiKey', 'name' => 'KM OpenAI API key']];
    }

    /** A retry would pay again for a request OpenAI may already have billed (ADR-046). */
    protected function modelRetried(): bool
    {
        return false;
    }

    protected function modelAddress(): array
    {
        return ["const OPENAI_HOST = 'https://api.openai.com';", 'url: `${OPENAI_HOST}/v1/responses`'];
    }

    public function test_it_takes_only_runs_of_its_own_version_and_reports_the_provider_laravel_accepts(): void
    {
        $nodes = $this->nodes();

        $this->assertSame('km-product-extraction-openai-v1', $this->workflow()['name']);
        $this->assertStringContainsString(
            "e.workflow_version !== '".AIWorkflowService::OPENAI_WORKFLOW_VERSION."'", $nodes['Check envelope']['parameters']['jsCode'],
        );
        $this->assertStringNotContainsString("'product-extraction.v1'", $nodes['Check envelope']['parameters']['jsCode']);
        // The callback names the provider ADR-045 requires; anything else makes no product or charge.
        $this->assertStringContainsString("provider: 'openai'", $nodes['Read OpenAI answer']['parameters']['jsCode']);
        $this->assertStringContainsString("provider: 'openai'", $nodes['Build OpenAI request']['parameters']['jsCode']);
        $this->assertStringNotContainsStringIgnoringCase('gemini', $this->raw());
        $this->assertStringNotContainsStringIgnoringCase('google', $this->raw());
        // Node and webhook ids are its own, so both exports can sit in one n8n.
        $legacy = json_decode((string) file_get_contents(base_path('n8n/workflows/product-extraction.v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $ids = fn (array $workflow): array => [...array_column($workflow['nodes'], 'id'), ...array_filter(array_column($workflow['nodes'], 'webhookId'))];
        $this->assertSame([], array_intersect($ids($legacy), $ids($this->workflow())));
    }
}
