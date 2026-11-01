<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The exported n8n workflow keeps the run contract (ADR-032 to ADR-036): it
 * listens where Laravel starts it, checks before it answers or works, keeps
 * its HMAC secrets in n8n credentials, talks only to the links Laravel
 * signed and Gemini, and keeps no execution history (ADR-037). Behaviour of
 * its Code nodes is tested with vitest.
 */
class N8nExtractionWorkflowTest extends TestCase
{
    private const NODE_TYPES = [
        'n8n-nodes-base.webhook', 'n8n-nodes-base.code', 'n8n-nodes-base.crypto', 'n8n-nodes-base.if',
        'n8n-nodes-base.respondToWebhook', 'n8n-nodes-base.httpRequest',
    ];

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        return json_decode((string) file_get_contents(base_path('n8n/workflows/product-extraction.v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> */
    private function nodes(): array
    {
        return array_column($this->workflow()['nodes'], null, 'name');
    }

    public function test_it_listens_where_laravel_starts_runs_and_reads_the_raw_body(): void
    {
        $webhook = $this->nodes()['Start run'];

        $this->assertSame('n8n-nodes-base.webhook', $webhook['type']);
        $this->assertSame('/webhook/'.$webhook['parameters']['path'], config('services.n8n.extraction_webhook_path'));
        $this->assertSame('POST', $webhook['parameters']['httpMethod']);
        // The signature covers the exact bytes, and n8n answers only after checking it.
        $this->assertTrue($webhook['parameters']['options']['rawBody']);
        $this->assertSame('responseNode', $webhook['parameters']['responseMode']);
        $this->assertSame(['Accept'], array_column($this->workflow()['connections']['Valid start?']['main'][0], 'node'));
        $this->assertSame(['Reject'], array_column($this->workflow()['connections']['Valid start?']['main'][1], 'node'));
        $this->assertArrayNotHasKey('Reject', $this->workflow()['connections']);
    }

    public function test_the_flow_is_exactly_check_then_answer_then_work(): void
    {
        $edges = [];
        foreach ($this->workflow()['connections'] as $from => $outputs) {
            foreach ($outputs['main'] as $index => $targets) {
                foreach ($targets as $target) {
                    $edges[] = "{$from}#{$index}->{$target['node']}";
                }
            }
        }

        // No path reaches a photo, Gemini or the callback without the signature check and 202.
        $this->assertSame([
            'Start run#0->Read request', 'Read request#0->Sign request', 'Sign request#0->Check envelope',
            'Check envelope#0->Valid start?', 'Valid start?#0->Accept', 'Valid start?#1->Reject', 'Accept#0->List photos',
            'List photos#0->Download photo', 'Download photo#0->Build Gemini request', 'Build Gemini request#0->Ready for model?',
            'Ready for model?#0->Call Gemini', 'Ready for model?#1->Build callback', 'Call Gemini#0->Read Gemini answer',
            'Read Gemini answer#0->Build callback', 'Build callback#0->Sign callback', 'Sign callback#0->Send callback',
        ], $edges);
    }

    public function test_secrets_sit_only_in_n8n_credentials(): void
    {
        $raw = (string) file_get_contents(base_path('n8n/workflows/product-extraction.v1.json'));
        $nodes = $this->nodes();

        // HMAC secrets and the Gemini key come from n8n's encrypted credential store: no environment access is needed.
        $this->assertStringNotContainsString('$env', $raw);
        $this->assertSame(['crypto' => ['id' => 'kmN8nStartToken', 'name' => 'KM extraction start token']], $nodes['Sign request']['credentials']);
        $this->assertSame(['crypto' => ['id' => 'kmN8nCallbackKey', 'name' => 'KM extraction callback secret']], $nodes['Sign callback']['credentials']);
        // The Gemini key travels in a header, never in the URL where an error message could carry it.
        $this->assertSame(['httpHeaderAuth' => ['id' => 'kmGeminiApiKey', 'name' => 'KM Gemini API key']], $nodes['Call Gemini']['credentials']);
        $this->assertSame(['genericCredentialType', 'httpHeaderAuth'], [$nodes['Call Gemini']['parameters']['authentication'], $nodes['Call Gemini']['parameters']['genericAuthType']]);
        $this->assertSame("={{ \$json.timestamp + '.' + \$json.raw }}", $nodes['Sign request']['parameters']['value']);
        $this->assertSame("={{ \$json.timestamp + '.' + \$json.body }}", $nodes['Sign callback']['parameters']['value']);
        foreach ($nodes as $node) {
            $this->assertContains($node['type'], self::NODE_TYPES, $node['name']);
            if (! in_array($node['name'], ['Sign request', 'Sign callback', 'Call Gemini'], true)) {
                $this->assertArrayNotHasKey('credentials', $node, $node['name']);
            }
        }
        // The Laravel address is set at import; the export holds only the placeholder.
        $this->assertStringContainsString("const BASE = '__KM_APP_BASE_URL__';", $nodes['Check envelope']['parameters']['jsCode']);
        // Nothing that looks like a key or token is written into the export.
        $this->assertDoesNotMatchRegularExpression('/[A-Fa-f0-9]{32,}|[A-Za-z0-9+_]{32,}={0,2}/', $raw);
    }

    public function test_code_nodes_reach_nothing_outside_their_items(): void
    {
        foreach ($this->nodes() as $node) {
            if ($node['type'] !== 'n8n-nodes-base.code') {
                continue;
            }
            // Reading a photo or the request body of this execution is the one helper allowed.
            $code = (string) preg_replace('/this\.helpers\.getBinaryDataBuffer\((0, \'data\'|index, \'photo\')\)/', '', $node['parameters']['jsCode']);
            foreach (['helpers', 'fetch(', 'require(', 'import(', 'process', '$env', '$vars', 'eval(', 'Function('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$node['name']} uses {$forbidden}");
            }
        }
    }

    public function test_it_calls_only_the_links_laravel_signed_and_keeps_no_history(): void
    {
        $workflow = $this->workflow();
        $http = array_filter($workflow['nodes'], fn (array $node): bool => $node['type'] === 'n8n-nodes-base.httpRequest');

        $this->assertSame(['Download photo', 'Call Gemini', 'Send callback'], array_values(array_column($http, 'name')));
        // Gemini is the one address not checked against Laravel's; it is fixed in the workflow.
        $this->assertStringContainsString("const GEMINI_HOST = 'https://generativelanguage.googleapis.com';", $this->nodes()['Build Gemini request']['parameters']['jsCode']);
        $this->assertStringContainsString('url: `${GEMINI_HOST}/v1beta/models/${MODEL}:generateContent`', $this->nodes()['Build Gemini request']['parameters']['jsCode']);
        // Only the single Gemini request is retried; photos are not, the callback is.
        $this->assertSame([false, true, true], array_values(array_map(fn (array $node): bool => $node['retryOnFail'] ?? false, $http)));
        $send = $this->nodes()['Send callback']['parameters'];
        // The body sent is the very string that was signed.
        $this->assertSame(['POST', 'raw', 'application/json', '={{ $json.body }}'], [$send['method'], $send['contentType'], $send['rawContentType'], $send['body']]);
        $this->assertSame([
            ['name' => 'X-KM-Timestamp', 'value' => '={{ $json.timestamp }}'],
            ['name' => 'X-KM-Signature', 'value' => "={{ 'v1=' + \$json.signature }}"],
            ['name' => 'Accept', 'value' => 'application/json'],
        ], $send['headerParameters']['parameters']);
        foreach ($http as $node) {
            // Photo and callback URLs were checked against the Laravel address in "Check envelope"; no redirect leaves them.
            $this->assertSame('={{ $json.url }}', $node['parameters']['url']);
            $this->assertFalse($node['parameters']['options']['redirect']['redirect']['followRedirects']);
        }
        // The checks themselves run in n8n/tests/product-extraction.workflow.spec.ts.
        $this->assertStringContainsString('`${BASE}/api/webhooks/internal/n8n/runs/${e.run_id}/assets/${asset.id}?`', $this->nodes()['Check envelope']['parameters']['jsCode']);

        $this->assertSame([
            'executionOrder' => 'v1', 'saveDataErrorExecution' => 'none', 'saveDataSuccessExecution' => 'none',
            'saveManualExecutions' => false, 'saveExecutionProgress' => false, 'executionTimeout' => 840,
            'callerPolicy' => 'workflowsFromSameOwner',
        ], $workflow['settings']);
        // An execution never outlives a whole run; the model step also stops at the run's deadline.
        $this->assertLessThan((int) config('karmandeman.ai.run_timeout_minutes') * 60, $workflow['settings']['executionTimeout']);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
