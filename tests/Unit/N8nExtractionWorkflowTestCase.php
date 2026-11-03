<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Each exported n8n extraction workflow keeps the run contract (ADR-032 to
 * ADR-036): it listens where Laravel starts it, checks before it answers or
 * works, claims the run before it reads a photo (ADR-038), keeps its HMAC
 * secrets in n8n credentials, talks only to the links Laravel signed and its
 * one model provider, and keeps no execution history. Behaviour of its Code
 * nodes is tested with vitest.
 */
abstract class N8nExtractionWorkflowTestCase extends TestCase
{
    private const NODE_TYPES = [
        'n8n-nodes-base.webhook', 'n8n-nodes-base.code', 'n8n-nodes-base.crypto', 'n8n-nodes-base.if',
        'n8n-nodes-base.respondToWebhook', 'n8n-nodes-base.httpRequest',
    ];

    /** The export under n8n/workflows/. */
    abstract protected function export(): string;

    /** The webhook path Laravel starts this export's runs at. */
    abstract protected function webhookPath(): string;

    /** The provider word in its model node names, such as "OpenAI". */
    abstract protected function provider(): string;

    /** @return array{httpHeaderAuth: array{id: string, name: string}} */
    abstract protected function modelCredential(): array;

    /** @return list<string> the lines that fix the model address in the request node */
    abstract protected function modelAddress(): array;

    /** @return array<string, mixed> */
    protected function workflow(): array
    {
        return json_decode($this->raw(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function raw(): string
    {
        return (string) file_get_contents(base_path('n8n/workflows/'.$this->export()));
    }

    /** @return array<string, array<string, mixed>> */
    protected function nodes(): array
    {
        return array_column($this->workflow()['nodes'], null, 'name');
    }

    public function test_it_listens_where_laravel_starts_runs_and_reads_the_raw_body(): void
    {
        $webhook = $this->nodes()['Start run'];

        $this->assertSame('n8n-nodes-base.webhook', $webhook['type']);
        $this->assertSame('/webhook/'.$webhook['parameters']['path'], $this->webhookPath());
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

        // No path reaches a photo, the model or the callback without the signature check, 202 and Laravel's claim.
        // A refused claim (output 1 of "Claimed?") leads nowhere.
        $model = $this->provider();
        $this->assertSame([
            'Start run#0->Read request', 'Read request#0->Sign request', 'Sign request#0->Check envelope',
            'Check envelope#0->Valid start?', 'Valid start?#0->Accept', 'Valid start?#1->Reject', 'Accept#0->Build claim',
            'Build claim#0->Sign claim', 'Sign claim#0->Claim run', 'Claim run#0->Claimed?', 'Claimed?#0->List photos',
            'List photos#0->Download photo', "Download photo#0->Build {$model} request", "Build {$model} request#0->Ready for model?",
            "Ready for model?#0->Call {$model}", 'Ready for model?#1->Build callback', "Call {$model}#0->Read {$model} answer",
            "Read {$model} answer#0->Build callback", 'Build callback#0->Sign callback', 'Sign callback#0->Send callback',
        ], $edges);
    }

    public function test_secrets_sit_only_in_n8n_credentials(): void
    {
        $raw = $this->raw();
        $nodes = $this->nodes();
        $call = 'Call '.$this->provider();

        // HMAC secrets and the model key come from n8n's encrypted credential store: no environment access is needed.
        $this->assertStringNotContainsString('$env', $raw);
        $this->assertSame(['crypto' => ['id' => 'kmN8nStartToken', 'name' => 'KM extraction start token']], $nodes['Sign request']['credentials']);
        $this->assertSame(['crypto' => ['id' => 'kmN8nCallbackKey', 'name' => 'KM extraction callback secret']], $nodes['Sign callback']['credentials']);
        $this->assertSame($nodes['Sign callback']['credentials'], $nodes['Sign claim']['credentials']);
        // The model key travels in a header, never in the URL where an error message could carry it.
        $this->assertSame($this->modelCredential(), $nodes[$call]['credentials']);
        $this->assertSame(['genericCredentialType', 'httpHeaderAuth'], [$nodes[$call]['parameters']['authentication'], $nodes[$call]['parameters']['genericAuthType']]);
        $this->assertSame("={{ \$json.timestamp + '.' + \$json.raw }}", $nodes['Sign request']['parameters']['value']);
        $this->assertSame("={{ \$json.timestamp + '.' + \$json.body }}", $nodes['Sign callback']['parameters']['value']);
        $this->assertSame($nodes['Sign callback']['parameters'], $nodes['Sign claim']['parameters']);
        foreach ($nodes as $node) {
            $this->assertContains($node['type'], self::NODE_TYPES, $node['name']);
            if (! in_array($node['name'], ['Sign request', 'Sign claim', 'Sign callback', $call], true)) {
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

        $this->assertSame(['Claim run', 'Download photo', 'Call '.$this->provider(), 'Send callback'], array_values(array_column($http, 'name')));
        // The model is the one address not checked against Laravel's; it is fixed in the workflow.
        foreach ($this->modelAddress() as $line) {
            $this->assertStringContainsString($line, $this->nodes()['Build '.$this->provider().' request']['parameters']['jsCode']);
        }
        // The claim, the single model request and the callback are retried; photos are not.
        $this->assertSame([true, false, true, true], array_values(array_map(fn (array $node): bool => $node['retryOnFail'] ?? false, $http)));
        $send = $this->nodes()['Send callback']['parameters'];
        // The claim is sent like the callback, and its answer is read even when it is a refusal.
        $claim = $this->nodes()['Claim run'];
        $this->assertSame($send, $claim['parameters']);
        $this->assertSame('continueRegularOutput', $claim['onError']);
        $this->assertSame('={{ ($json.data || {}).claimed === true }}', $this->nodes()['Claimed?']['parameters']['conditions']['conditions'][0]['leftValue']);
        $this->assertStringContainsString('url: `${envelope.callback_url}/claim`', $this->nodes()['Build claim']['parameters']['jsCode']);
        $this->assertSame(route('webhooks.n8n.runs', absolute: false).'/claim', route('webhooks.n8n.run-claims', absolute: false));
        // The body sent is the very string that was signed.
        $this->assertSame(['POST', 'raw', 'application/json', '={{ $json.body }}'], [$send['method'], $send['contentType'], $send['rawContentType'], $send['body']]);
        $this->assertSame([
            ['name' => 'X-KM-Timestamp', 'value' => '={{ $json.timestamp }}'],
            ['name' => 'X-KM-Signature', 'value' => "={{ 'v1=' + \$json.signature }}"],
            ['name' => 'Accept', 'value' => 'application/json'],
        ], $send['headerParameters']['parameters']);
        foreach ($http as $node) {
            // Claim, photo and callback URLs were checked against the Laravel address in "Check envelope"; no redirect leaves them.
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
    protected function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
