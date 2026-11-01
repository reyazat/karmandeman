<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Project\DispatchExtractionRunAction;
use App\Jobs\AI\DispatchProductExtraction;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use App\Services\AI\ProductExtractionOutputValidator;
use App\Services\AI\RunAssetUrlSigner;
use App\Services\AI\WorkflowSignature;
use App\Services\Storage\PrivateAssetStore;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Step 4 run contract: finish-input -> signed n8n start -> signed, untrusted callback (ADR-032). */
class AIExtractionRunTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CALLBACK_SECRET = 'test-callback-secret';

    private const WORKFLOW_TOKEN = 'test-workflow-token';

    private int $keys = 0;

    private int $n8nStatus = 200;

    /** cURL error the n8n start fails with, or null to answer with $n8nStatus. */
    private ?string $n8nFailure = null;

    private int $n8nStarts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(aiProviders: ['google']);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => self::WORKFLOW_TOKEN,
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'karmandeman.ai.extraction_enabled' => true,
        ]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'n8n.test/*' => function (HttpRequest $request) {
                $this->n8nStarts++;

                return $this->n8nFailure === null
                    ? Http::response(['accepted' => $this->n8nStatus === 200], $this->n8nStatus)
                    : Http::failedConnection($this->n8nFailure)($request);
            },
        ]);
        Storage::fake('assets');
        Queue::fake([DispatchProductExtraction::class, SendTelegramMessage::class]);
    }

    private function seller(int $telegramId): User
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'message_id' => 1, 'date' => time(), 'text' => '/start',
                'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $user = User::query()->latest('id')->firstOrFail();
        $this->grantPilotConsent($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function photo(string $project, int $seed): string
    {
        return $this->post("/api/v1/projects/{$project}/assets", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', ImageFixture::make($seed)),
        ], ['Idempotency-Key' => 'asset-key-'.++$this->keys, 'Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function version(string $project): int
    {
        return (int) $this->getJson("/api/v1/projects/{$project}")->assertOk()->json('data.version');
    }

    private function finish(string $project, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $this->version($project)], ['Idempotency-Key' => $key ?? 'finish-'.++$this->keys]);
    }

    private function state(string $project): string
    {
        return (string) DB::table('projects')->where('public_id', $project)->value('state');
    }

    /** A batch with two photos whose run has started. */
    private function extracting(int $telegramId): array
    {
        $this->seller($telegramId);
        $project = $this->project();
        $photos = [$this->photo($project, $telegramId), $this->photo($project, $telegramId + 1)];
        $this->finish($project)->assertStatus(202)->assertJsonPath('data.state', 'extracting');
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();

        return [$project, $photos, $run];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function runResult(AiWorkflowRun $run, array $candidates, array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 'product-extraction.output.v1',
            'run_id' => $run->public_id,
            'status' => 'succeeded',
            'error_code' => null,
            'usage' => ['model_requests' => 3, 'input_tokens' => 1200, 'output_tokens' => 300, 'provider' => 'fixture', 'model' => 'fixture-1'],
            'candidates' => $candidates,
        ], $overrides);
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, mixed>
     */
    private function candidate(string $name, array $sources, ?array $price = null, ?string $sku = null): array
    {
        return [
            'name' => $name, 'sku' => $sku, 'description_fa' => null, 'price' => $price,
            'attributes' => [['name' => 'رنگ', 'values' => ['مشکی']]], 'category_path' => null,
            'source_asset_ids' => $sources,
        ];
    }

    private function sendCallback(array|string $body, ?int $timestamp = null, ?string $secret = self::CALLBACK_SECRET): TestResponse
    {
        return $this->signedPost('/api/webhooks/internal/n8n/runs', $body, $timestamp, $secret);
    }

    private function sendClaim(AiWorkflowRun $run, string $execution, ?string $secret = self::CALLBACK_SECRET): TestResponse
    {
        return $this->signedPost('/api/webhooks/internal/n8n/runs/claim', [
            'schema_version' => 'product-extraction.claim.v1', 'run_id' => $run->public_id, 'execution_id' => $execution,
        ], secret: $secret);
    }

    private function signedPost(string $uri, array|string $body, ?int $timestamp = null, ?string $secret = self::CALLBACK_SECRET): TestResponse
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        $timestamp ??= now()->getTimestamp();
        $headers = [
            WorkflowSignature::HEADER_TIMESTAMP => (string) $timestamp,
            WorkflowSignature::HEADER_SIGNATURE => (new WorkflowSignature)->sign((string) $secret, $timestamp, $raw),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), $raw);
    }

    public function test_finish_input_starts_one_run_for_the_unclaimed_photos(): void
    {
        $this->seller(900);
        $project = $this->project();
        $photos = [$this->photo($project, 1), $this->photo($project, 2)];

        $this->finish($project, 'finish-once')->assertStatus(202)->assertJsonPath('data.state', 'extracting');
        $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => 3], ['Idempotency-Key' => 'finish-once'])->assertStatus(202);

        $run = AiWorkflowRun::query()->sole();
        $this->assertSame('queued', $run->status->value);
        $this->assertSame($photos, $run->input_asset_ids);
        $this->assertSame('product-extraction.v1', $run->workflow_version);
        Queue::assertPushedOn('ai', DispatchProductExtraction::class, fn (DispatchProductExtraction $job): bool => $job->runId === $run->id);
        Queue::assertPushed(DispatchProductExtraction::class, 1);
        // The batch takes no more input while its photos are read.
        $this->post("/api/v1/projects/{$project}/assets", ['file' => UploadedFile::fake()->createWithContent('x.jpg', ImageFixture::make(3))],
            ['Idempotency-Key' => 'late-photo', 'Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_without_extraction_or_in_production_finish_input_stays_manual(): void
    {
        config(['karmandeman.ai.extraction_enabled' => false]);
        $this->seller(901);
        $project = $this->project();
        $this->photo($project, 1);
        $this->finish($project)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_HAS_NO_PRODUCTS');

        config(['karmandeman.ai.extraction_enabled' => true]);
        $this->app['env'] = 'production';
        $this->assertFalse(app(AIWorkflowService::class)->extractionEnabled());
        $this->app['env'] = 'testing';
        $this->assertTrue(app(AIWorkflowService::class)->extractionEnabled());
        $this->assertSame(0, AiWorkflowRun::query()->count());
    }

    public function test_too_many_photos_are_refused_before_any_run(): void
    {
        config(['karmandeman.ai.max_assets_per_run' => 1]);
        $this->seller(902);
        $project = $this->project();
        $this->photo($project, 1);
        $this->photo($project, 2);

        $this->finish($project)->assertStatus(422)->assertJsonPath('error.code', 'EXTRACTION_TOO_MANY_PHOTOS');
        $this->assertSame('collecting_input', $this->state($project));
        $this->assertSame(0, AiWorkflowRun::query()->count());
    }

    public function test_photos_too_large_for_one_model_request_are_refused_before_any_run(): void
    {
        $this->onboardedSeller(906);
        $project = $this->project();
        $this->photo($project, 1);
        $this->photo($project, 2);
        $sizes = (int) Asset::query()->sum('size_bytes');
        config(['karmandeman.ai.max_input_bytes_per_run' => $sizes - 1]);

        $this->finish($project)->assertStatus(422)->assertJsonPath('error.code', 'EXTRACTION_PHOTOS_TOO_LARGE')
            ->assertJsonPath('error.details.max_bytes', $sizes - 1);
        $this->sendDone(906);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'حجم عکس‌های این پروژه'));
        $this->assertSame('collecting_input', $this->state($project));
        $this->assertSame(0, AiWorkflowRun::query()->count());

        config(['karmandeman.ai.max_input_bytes_per_run' => $sizes]);
        $this->finish($project)->assertStatus(202);
    }

    public function test_without_a_policy_naming_the_provider_finish_input_stays_manual(): void
    {
        $this->useApprovedPilotPolicy(aiProviders: ['another-provider']);
        $this->seller(907);
        $project = $this->project();
        $this->photo($project, 1);

        // The seller never accepted sending photos to Google: no run, the manual path stays (ADR-037).
        $this->assertFalse(app(AIWorkflowService::class)->extractionEnabled());
        $this->finish($project)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_HAS_NO_PRODUCTS');
        $this->assertSame(0, AiWorkflowRun::query()->count());
    }

    public function test_a_start_that_failed_on_the_way_is_retried_and_only_one_execution_works(): void
    {
        [, , $run] = $this->extracting(908);

        // Whether or not n8n got it, a failed start throws so the job retries (ADR-038 makes a repeat safe).
        foreach ([
            'cURL error 28: Operation timed out after 10002 milliseconds with 0 bytes received',
            'cURL error 28: Connection timed out after 5001 milliseconds',
            'cURL error 7: Failed to connect to n8n.test port 80',
            'cURL error 56: Recv failure: Connection reset by peer',
        ] as $failure) {
            $this->n8nFailure = $failure;
            try {
                app(DispatchExtractionRunAction::class)->execute($run->id);
                $this->fail('A failed start must throw so the job retries.');
            } catch (RuntimeException $e) {
                $this->assertSame('n8n workflow endpoint is unreachable.', $e->getMessage());
            }
            $this->assertSame('queued', $run->fresh()->status->value);
        }
        // The first start did reach n8n: its execution claims, a repeated start's execution is refused.
        $this->sendClaim($run, '11')->assertOk();
        $this->n8nFailure = null;
        app(DispatchExtractionRunAction::class)->execute($run->id);
        $this->assertSame(4, $this->n8nStarts);
        $this->sendClaim($run, '12')->assertStatus(409)->assertJsonPath('error.code', 'RUN_ALREADY_CLAIMED');
    }

    public function test_dispatch_sends_a_signed_envelope_with_public_ids_only_once(): void
    {
        [$project, $photos, $run] = $this->extracting(903);

        app(DispatchExtractionRunAction::class)->execute($run->id);
        app(DispatchExtractionRunAction::class)->execute($run->id);

        $sent = Http::recorded(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://n8n.test/'));
        $this->assertCount(1, $sent);
        [$request] = $sent[0];
        $this->assertSame('http://n8n.test/webhook/km-product-extraction-v1', $request->url());
        $this->assertTrue((new WorkflowSignature)->verify(self::WORKFLOW_TOKEN, $request->header(WorkflowSignature::HEADER_TIMESTAMP)[0],
            $request->body(), $request->header(WorkflowSignature::HEADER_SIGNATURE)[0], time(), 300));
        $envelope = json_decode($request->body(), true);
        $schema = json_decode((string) file_get_contents(base_path('n8n/schemas/product-extraction.input.v1.json')), true);
        $this->assertEqualsCanonicalizing($schema['required'], array_keys($envelope));
        $this->assertEqualsCanonicalizing($schema['properties']['assets']['items']['required'], array_keys($envelope['assets'][0]));
        $this->assertSame($run->public_id, $envelope['run_id']);
        $this->assertSame($project, $envelope['project_id']);
        $this->assertSame($photos, array_column($envelope['assets'], 'id'));
        $this->assertSame(1, $envelope['attempt']);
        $this->assertStringNotContainsString('workspace', $request->body());
        $this->assertStringNotContainsString('Batch', $request->body());
        $this->assertSame('dispatched', $run->fresh()->status->value);
    }

    public function test_a_failed_start_retries_then_reopens_the_batch(): void
    {
        $this->n8nStatus = 500;
        [$project, , $run] = $this->extracting(904);

        try {
            app(DispatchExtractionRunAction::class)->execute($run->id);
            $this->fail('A failed start must throw so the job retries.');
        } catch (RuntimeException $e) {
            $this->assertSame('n8n workflow start failed with HTTP 500.', $e->getMessage());
        }
        $this->assertSame('queued', $run->fresh()->status->value);

        (new DispatchProductExtraction($run->id))->failed(new RuntimeException('down'));

        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame('DISPATCH_FAILED', $run->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($project));
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'انجام نشد'));
    }

    public function test_a_valid_callback_stores_suggested_candidates_with_their_photos(): void
    {
        [$project, $photos, $run] = $this->extracting(905);
        $body = $this->runResult($run, [
            $this->candidate('كيف چرمی', [$photos[0]], ['amount' => 120000, 'unit' => 'IRT'], 'BAG-1'),
            $this->candidate('کفش', [$photos[0], strtoupper($photos[1])]),
        ]);

        $this->sendCallback($body)->assertOk()->assertJsonPath('data.outcome', 'recorded');

        $this->assertSame('review_required', $this->state($project));
        $run->refresh();
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(2, $run->candidate_count);
        $this->assertSame(3, $run->usage['model_requests']);
        $products = Product::query()->orderBy('id')->get();
        $this->assertCount(2, $products);
        $this->assertTrue($products->every(fn (Product $product): bool => $product->needs_confirmation));
        $payload = ProductRevision::query()->where('product_id', $products[0]->id)->sole()->payload;
        $this->assertSame(['value' => 'کیف چرمی', 'status' => 'suggested'], $payload['name']);
        $this->assertSame(1_200_000, $payload['pricing']['regular_price_irr']);
        $this->assertSame('ai_extraction', $payload['pricing']['source']);
        $this->assertSame('suggested', $payload['pricing']['status']);
        $this->assertSame([$photos[0]], $payload['image_asset_ids']);
        $this->assertContains(['field' => 'name', 'source' => 'ai_extraction', 'source_asset_ids' => [$photos[0]]], $payload['evidence']);
        $second = ProductRevision::query()->where('product_id', $products[1]->id)->sole()->payload;
        $this->assertSame($photos, $second['image_asset_ids']);
        // Each photo is claimed by the first candidate citing it.
        $claims = DB::table('assets')->orderBy('id')->pluck('claimed_by_product_id')->all();
        $this->assertSame([$products[0]->id, $products[1]->id], $claims);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '2 محصول پیشنهاد شد'));

        // Suggested values never pass Gate A unconfirmed.
        $this->postJson("/api/v1/products/{$products[0]->public_id}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-ai'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_ATTRIBUTE_REQUIRES_CONFIRMATION');

        // The same body again is answered; a different one is refused.
        $this->sendCallback($body)->assertOk()->assertJsonPath('data.outcome', 'already_recorded');
        $this->sendCallback($this->runResult($run, []))->assertStatus(409)->assertJsonPath('error.code', 'RUN_ALREADY_COMPLETED');
        $this->assertSame(2, Product::query()->count());
    }

    public function test_unsigned_forged_and_stale_callbacks_are_refused(): void
    {
        [, $photos, $run] = $this->extracting(906);
        $body = $this->runResult($run, [$this->candidate('کیف', [$photos[0]])]);

        $this->call('POST', '/api/webhooks/internal/n8n/runs', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body))
            ->assertStatus(401);
        $this->sendCallback($body, secret: 'wrong-secret')->assertStatus(401);
        $this->sendCallback($body, timestamp: now()->getTimestamp() - 301)->assertStatus(401);
        $this->sendCallback($body, timestamp: now()->getTimestamp() + 301)->assertStatus(401);
        config(['services.n8n.callback_secret' => '']);
        $this->sendCallback($body, secret: '')->assertStatus(401);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame('queued', $run->fresh()->status->value);
    }

    public function test_an_unknown_run_is_not_found(): void
    {
        $this->extracting(907);
        $this->sendCallback($this->runResult(new AiWorkflowRun(['workflow' => 'x']), [], ['run_id' => '00000000-0000-4000-8000-000000000000']))
            ->assertStatus(404)->assertJsonPath('error.code', 'RUN_NOT_FOUND');
        $this->sendCallback('not json')->assertStatus(422);
        $this->sendCallback('[1,2]')->assertStatus(422);
    }

    /**
     * @return iterable<string, array{\Closure(array, list<string>, string): array}>
     */
    public static function invalidOutputs(): iterable
    {
        yield 'unknown root key' => [fn (array $body): array => $body + ['workspace_id' => 1]];
        yield 'unknown candidate key' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['status' => 'confirmed']]])];
        yield 'price without unit' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['price' => ['amount' => 5]]]])];
        yield 'zero price' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['price' => ['amount' => 0, 'unit' => 'IRT']]]])];
        yield 'photo outside the run' => [fn (array $body, array $photos, string $foreign): array => array_replace_recursive($body, ['candidates' => [['source_asset_ids' => [$foreign]]]])];
        yield 'no photo' => [function (array $body): array {
            $body['candidates'][0]['source_asset_ids'] = [];

            return $body;
        }];
        yield 'empty price object' => [function (array $body): array {
            $body['candidates'][0]['price'] = [];

            return $body;
        }];
        yield 'price amount as string' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['price' => ['amount' => '1000', 'unit' => 'IRT']]]])];
        yield 'price amount as boolean' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['price' => ['amount' => true, 'unit' => 'IRT']]]])];
        yield 'usage as string' => [fn (array $body): array => array_replace_recursive($body, ['usage' => ['model_requests' => '3']])];
        yield 'one photo twice in a candidate' => [function (array $body, array $photos): array {
            $body['candidates'][0]['source_asset_ids'] = [$photos[0], strtoupper($photos[0])];

            return $body;
        }];
        yield 'empty name after normalization' => [fn (array $body): array => array_replace_recursive($body, ['candidates' => [['name' => "\u{200C}\u{0640}"]]])];
        yield 'failed with candidates' => [fn (array $body): array => ['status' => 'failed', 'error_code' => 'MODEL_ERROR'] + $body];
        yield 'negative usage' => [fn (array $body): array => array_replace_recursive($body, ['usage' => ['input_tokens' => -1]])];
    }

    #[DataProvider('invalidOutputs')]
    public function test_a_signed_body_that_breaks_the_schema_fails_the_run(\Closure $mutate): void
    {
        [$project, $photos, $run] = $this->extracting(910);
        // A photo of another workspace's batch.
        $this->seller(911);
        $foreign = $this->photo($this->project(), 99);

        $this->sendCallback($mutate($this->runResult($run, [$this->candidate('کیف', [$photos[0]])]), $photos, $foreign))
            ->assertStatus(422)->assertJsonPath('error.code', 'WORKFLOW_OUTPUT_INVALID');

        $this->assertSame(0, Product::query()->count());
        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame('OUTPUT_INVALID', $run->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($project));
    }

    public function test_reported_failure_and_usage_over_cap_store_nothing(): void
    {
        [$project, $photos, $run] = $this->extracting(912);
        $this->sendCallback($this->runResult($run, [], ['status' => 'failed', 'error_code' => 'MODEL_UNAVAILABLE']))
            ->assertOk()->assertJsonPath('data.outcome', 'run_failed');
        $this->assertSame('MODEL_UNAVAILABLE', $run->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($project));

        $this->finish($project)->assertStatus(202);
        $second = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->assertNotSame($run->id, $second->id);
        config(['karmandeman.ai.max_output_tokens' => 100]);
        $this->sendCallback($this->runResult($second, [$this->candidate('کیف', [$photos[0]])]))
            ->assertOk()->assertJsonPath('data.outcome', 'run_failed');
        $this->assertSame('USAGE_OVER_CAP', $second->fresh()->error_code);
        $this->assertSame(300, $second->fresh()->usage['output_tokens']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_cancel_timeout_and_erasure_contain_a_late_callback(): void
    {
        [$project, $photos, $run] = $this->extracting(913);
        $this->postJson("/api/v1/projects/{$project}/cancel", ['version' => $this->version($project)], ['Idempotency-Key' => 'cancel-run'])
            ->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->assertSame('cancelled', $run->fresh()->status->value);
        $this->sendCallback($this->runResult($run, [$this->candidate('کیف', [$photos[0]])]))->assertOk()->assertJsonPath('data.outcome', 'discarded');

        [$second, $secondPhotos, $late] = $this->extracting(914);
        $this->travel(16)->minutes();
        $this->artisan('ai:expire-runs')->assertSuccessful();
        $this->assertSame('timed_out', $late->fresh()->status->value);
        $this->assertSame('collecting_input', $this->state($second));
        $this->sendCallback($this->runResult($late, [$this->candidate('کیف', [$secondPhotos[0]])]))->assertOk()->assertJsonPath('data.outcome', 'discarded');

        $this->finish($second)->assertStatus(202);
        $third = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $user = User::query()->latest('id')->firstOrFail();
        DB::table('workspaces')->where('id', app(WorkspaceRepositoryInterface::class)->currentFor($user)->id)->update(['status' => 'deletion_pending']);
        $this->sendCallback($this->runResult($third, [$this->candidate('کیف', [$secondPhotos[0]])]))->assertOk()->assertJsonPath('data.outcome', 'discarded');
        $this->assertSame('queued', $third->fresh()->status->value);
        app(DispatchExtractionRunAction::class)->execute($third->id);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://n8n.test/'));

        $this->assertSame(0, Product::query()->count());
    }

    public function test_a_suggested_sku_never_takes_one_already_held(): void
    {
        $this->seller(915);
        $held = $this->project();
        $this->postJson("/api/v1/projects/{$held}/products", [
            'name' => ['value' => 'کیف قدیمی', 'status' => 'confirmed'],
            'sku' => ['value' => 'BAG-1', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'held-sku'])->assertCreated();
        $project = $this->project();
        $photo = $this->photo($project, 7);
        $this->finish($project)->assertStatus(202);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();

        $this->sendCallback($this->runResult($run, [$this->candidate('کیف تازه', [$photo], sku: 'bag-1')]))->assertOk();

        $created = Product::query()->latest('id')->firstOrFail();
        $payload = ProductRevision::query()->where('product_id', $created->id)->sole()->payload;
        $this->assertNull($payload['sku']['value']);
        $this->assertSame('کیف تازه', $payload['name']['value']);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_no_candidate_reopens_the_batch(): void
    {
        [$project, , $run] = $this->extracting(916);
        $this->sendCallback($this->runResult($run, []))->assertOk()->assertJsonPath('data.outcome', 'recorded');

        $this->assertSame('collecting_input', $this->state($project));
        $this->assertSame(0, $run->fresh()->candidate_count);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'محصولی پیدا نشد'));
    }

    /** A seller who finished bot onboarding, so /done reaches the batch. */
    private function onboardedSeller(int $telegramId): void
    {
        $user = $this->seller($telegramId);
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
    }

    private function sendDone(int $telegramId): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'message_id' => random_int(2, 10_000_000), 'date' => time(), 'text' => '/done',
                'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_the_bot_done_starts_extraction_and_waits(): void
    {
        $this->onboardedSeller(917);
        $project = $this->project();
        $this->photo($project, 8);

        $this->sendDone(917);
        $this->assertSame('extracting', $this->state($project));
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'استخراج می‌شوند'));
        $this->sendDone(917);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'در حال استخراج'));
        $this->assertSame(1, AiWorkflowRun::query()->count());
    }

    public function test_photos_already_used_are_never_sent_again(): void
    {
        $this->seller(918);
        $project = $this->project();
        $used = $this->photo($project, 30);
        $this->postJson("/api/v1/projects/{$project}/products", [
            'name' => ['value' => 'کیف', 'status' => 'confirmed'],
            'image_asset_ids' => [$used],
        ], ['Idempotency-Key' => 'api-product-photo'])->assertCreated();

        // The only photo belongs to an API product: the manual path runs, no model call.
        $this->finish($project)->assertOk()->assertJsonPath('data.state', 'review_required');
        $this->assertSame(0, AiWorkflowRun::query()->count());

        $second = $this->project();
        $read = $this->photo($second, 31);
        $this->finish($second)->assertStatus(202);
        $run = AiWorkflowRun::query()->sole();
        $this->sendCallback($this->runResult($run, []))->assertOk();
        $this->assertSame('collecting_input', $this->state($second));

        // A photo a succeeded run already read, even without a result, is not read again.
        $this->finish($second)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_HAS_NO_PRODUCTS');
        $fresh = $this->photo($second, 32);
        $this->finish($second)->assertStatus(202);
        $this->assertSame([$fresh], AiWorkflowRun::query()->latest('id')->firstOrFail()->input_asset_ids);
        $this->assertNotSame($read, $fresh);
    }

    public function test_an_erased_account_run_never_blocks_other_expiries(): void
    {
        [, , $erased] = $this->extracting(919);
        DB::table('workspaces')->where('id', $erased->workspace_id)->update(['status' => 'deletion_pending']);
        [$other, , $run] = $this->extracting(921);
        $this->travel(16)->minutes();

        $this->assertSame([$run->id], app(AiWorkflowRunRepositoryInterface::class)->overdueIds(now()->toImmutable(), 1));
        $this->artisan('ai:expire-runs')->assertSuccessful();

        $this->assertSame('timed_out', $run->fresh()->status->value);
        $this->assertSame('collecting_input', $this->state($other));
        // The erased account's run waits for the purge untouched.
        $this->assertSame('queued', $erased->fresh()->status->value);
    }

    public function test_switching_extraction_off_before_the_start_reopens_the_batch(): void
    {
        [$project, , $run] = $this->extracting(923);
        config(['karmandeman.ai.extraction_enabled' => false]);

        app(DispatchExtractionRunAction::class)->execute($run->id);

        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame('EXTRACTION_DISABLED', $run->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($project));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://n8n.test/'));
    }

    public function test_only_the_first_execution_to_claim_a_run_works_on_it(): void
    {
        [, , $run] = $this->extracting(924);

        // n8n claimed before Laravel recorded the start: the run is dispatched and a retried job sends nothing (ADR-038).
        $this->sendClaim($run, '501')->assertOk()->assertJsonPath('data.claimed', true);
        $this->assertSame('dispatched', $run->fresh()->status->value);
        $this->assertSame('501', $run->fresh()->claimed_by_execution);
        app(DispatchExtractionRunAction::class)->execute($run->id);
        $this->assertSame(0, $this->n8nStarts);

        // The same execution may retry its claim; any other execution stops before reading a photo.
        $this->sendClaim($run, '501')->assertOk();
        $this->sendClaim($run, '502')->assertStatus(409)->assertJsonPath('error.code', 'RUN_ALREADY_CLAIMED');
        $this->assertSame('501', $run->fresh()->claimed_by_execution);

        // The claimed run still ends the usual way.
        $this->sendCallback($this->runResult($run, []))->assertOk();
        $this->sendClaim($run, '501')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
    }

    public function test_forged_malformed_and_unknown_claims_are_refused(): void
    {
        [, , $run] = $this->extracting(925);
        $claim = ['schema_version' => 'product-extraction.claim.v1', 'run_id' => $run->public_id, 'execution_id' => '7'];

        $this->sendClaim($run, '7', 'another-secret')->assertUnauthorized();
        $this->signedPost('/api/webhooks/internal/n8n/runs/claim', $claim, now()->getTimestamp() - 301)->assertUnauthorized();
        // A signed claim replayed to the callback, or any body of another schema, is refused and never fails the run.
        $this->signedPost('/api/webhooks/internal/n8n/runs', $claim)->assertStatus(422)->assertJsonPath('error.details.fields', ['schema_version']);
        $this->sendCallback(['schema_version' => 'product-extraction.output.v2'] + $this->runResult($run, []))
            ->assertStatus(422)->assertJsonPath('error.details.fields', ['schema_version']);
        $this->assertSame('queued', $run->fresh()->status->value);
        // The start token is not the callback secret.
        $this->sendClaim($run, '7', self::WORKFLOW_TOKEN)->assertUnauthorized();
        foreach ([
            'not json',
            '[]',
            json_encode([...$claim, 'extra' => 1]),
            json_encode([...$claim, 'execution_id' => 'a b']),
            json_encode([...$claim, 'execution_id' => 7]),
            json_encode([...$claim, 'run_id' => strtoupper($run->public_id)]),
            json_encode([...$claim, 'schema_version' => 'product-extraction.claim.v2']),
        ] as $body) {
            $this->signedPost('/api/webhooks/internal/n8n/runs/claim', $body)->assertStatus(422)->assertJsonPath('error.code', 'CLAIM_INVALID');
        }
        $this->signedPost('/api/webhooks/internal/n8n/runs/claim', [...$claim, 'run_id' => '01a1950c-d595-72bc-91dd-a95ee64d4495'])
            ->assertNotFound()->assertJsonPath('error.code', 'RUN_NOT_FOUND');
        $this->assertNull($run->fresh()->claimed_by_execution);
    }

    public function test_a_run_that_can_no_longer_change_its_batch_is_never_claimed(): void
    {
        [$project, , $cancelled] = $this->extracting(926);
        $this->postJson("/api/v1/projects/{$project}/cancel", ['version' => $this->version($project)], ['Idempotency-Key' => 'cancel-claim'])->assertOk();
        $this->sendClaim($cancelled, '1')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');

        [, , $late] = $this->extracting(927);
        // Four minutes left is too little for the model: refused, and the run expires as usual.
        $this->travel(11)->minutes();
        $this->sendClaim($late, '2')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        $this->assertSame('queued', $late->fresh()->status->value);
        $this->travel(5)->minutes();
        $this->sendClaim($late, '2')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        $this->travelBack();

        [$second, , $erased] = $this->extracting(928);
        $workspace = $erased->workspace_id;
        DB::table('workspaces')->where('id', $workspace)->update(['status' => 'deletion_pending']);
        $this->sendClaim($erased, '3')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        DB::table('workspaces')->where('id', $workspace)->update(['status' => 'active']);

        // Switched off after the start: the run closes now and the batch reopens before any model call.
        config(['karmandeman.ai.extraction_enabled' => false]);
        $this->sendClaim($erased, '4')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        $this->assertSame('failed', $erased->fresh()->status->value);
        $this->assertSame('EXTRACTION_DISABLED', $erased->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($second));
        foreach ([$cancelled, $late, $erased] as $run) {
            $this->assertNull($run->fresh()->claimed_by_execution);
        }
    }

    public function test_every_claim_checks_the_policy_and_consent_the_photos_leave_under(): void
    {
        [$first, , $claimed] = $this->extracting(929);
        $this->sendClaim($claimed, '1')->assertOk();
        // A new bundle that still names Google, which the seller has not accepted: even the
        // execution holding the claim stops before reading a photo.
        $this->useApprovedPilotPolicy('test-policy-v2', aiProviders: ['google']);
        $this->sendClaim($claimed, '1')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        $this->assertSame('POLICY_CONSENT_REQUIRED', $claimed->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($first));

        [$second, , $dropped] = $this->extracting(931);
        // The policy in force no longer names Google.
        $this->useApprovedPilotPolicy('test-policy-v3');
        $this->sendClaim($dropped, '2')->assertStatus(409)->assertJsonPath('error.code', 'RUN_CLOSED');
        $this->assertSame('EXTRACTION_DISABLED', $dropped->fresh()->error_code);
        $this->assertSame('collecting_input', $this->state($second));
        $this->assertNull($dropped->fresh()->claimed_by_execution);
    }

    public function test_a_photo_link_works_only_after_the_run_is_claimed(): void
    {
        [, $photos, $run] = $this->extracting(932);
        app(DispatchExtractionRunAction::class)->execute($run->id);
        $sent = Http::recorded(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://n8n.test/'));
        $url = json_decode($sent[0][0]->body(), true)['assets'][0]['download_url'];

        // A workflow that skips the claim gets no photo, so it has nothing to send to the model.
        $this->get($url)->assertForbidden();
        $this->sendClaim($run, '9')->assertOk();
        $this->get($url)->assertOk();
        $this->assertSame($photos[0], json_decode($sent[0][0]->body(), true)['assets'][0]['id']);
    }

    /** @return list<array<string, mixed>> the assets of the envelope the run was started with */
    private function startedAssets(AiWorkflowRun $run): array
    {
        app(DispatchExtractionRunAction::class)->execute($run->id);
        $sent = Http::recorded(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://n8n.test/'));
        $this->sendClaim($run, 'exec-'.$run->id)->assertOk();

        return json_decode($sent[count($sent) - 1][0]->body(), true)['assets'];
    }

    public function test_n8n_fetches_each_run_photo_without_metadata_through_its_signed_link(): void
    {
        [, $photos, $run] = $this->extracting(930);
        $assets = $this->startedAssets($run);
        $this->assertSame($photos, array_column($assets, 'id'));

        $response = $this->get($assets[0]['download_url'])->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $bytes = $response->getContent();
        // The stored fixture carries a comment segment; the copy sent to n8n does not.
        $this->assertStringNotContainsString('seed 930', (string) $bytes);
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring((string) $bytes), 0, 2));
        $this->get($assets[1]['download_url'])->assertOk();
    }

    public function test_a_photo_link_works_only_for_its_run_photo_and_until_the_run_ends(): void
    {
        [, $photos, $run] = $this->extracting(931);
        [$first, $second] = $this->startedAssets($run);
        $forbidden = fn (string $url) => $this->get($url)->assertStatus(403)->assertJsonPath('error.code', 'RUN_ASSET_FORBIDDEN');
        parse_str((string) parse_url($first['download_url'], PHP_URL_QUERY), $query);

        $forbidden(str_replace($query['signature'], str_repeat('0', 64), $first['download_url']));
        $forbidden(str_replace($photos[0], $photos[1], $first['download_url']));
        $forbidden(str_replace('expires='.$query['expires'], 'expires='.($query['expires'] + 3600), $first['download_url']));
        $forbidden(strtok($first['download_url'], '?'));
        $forbidden(str_replace('signature=', 'signature[]=', $first['download_url']));
        $forbidden(str_replace('expires=', 'expires[]=', $first['download_url']));
        // A link signed with the right key for a photo the run was not given.
        $this->seller(932);
        $foreign = $this->photo($this->project(), 77);
        $forbidden(app(RunAssetUrlSigner::class)->url($run, $foreign));

        // Once the run has its result, no photo is served any more.
        $this->sendCallback($this->runResult($run, []))->assertOk();
        $forbidden($first['download_url']);
        $forbidden($second['download_url']);
    }

    public function test_photo_links_stop_at_the_deadline_and_on_erasure(): void
    {
        [, , $run] = $this->extracting(933);
        [$asset] = $this->startedAssets($run);
        DB::table('workspaces')->where('id', $run->workspace_id)->update(['status' => 'deletion_pending']);
        $this->get($asset['download_url'])->assertStatus(403);
        // With the key already gone mid-purge, the answer is still 403, without asking the key service.
        $keys = DB::table('workspace_data_keys')->where('workspace_id', $run->workspace_id)->get()->map(fn ($row): array => (array) $row)->all();
        DB::table('workspace_data_keys')->where('workspace_id', $run->workspace_id)->delete();
        $this->get($asset['download_url'])->assertStatus(403)->assertJsonPath('error.code', 'RUN_ASSET_FORBIDDEN');
        DB::table('workspace_data_keys')->insert($keys);
        DB::table('workspaces')->where('id', $run->workspace_id)->update(['status' => 'active']);
        $this->get($asset['download_url'])->assertOk();

        $this->travel(16)->minutes();
        $this->get($asset['download_url'])->assertStatus(403);
    }

    public function test_links_use_the_internal_app_address_and_unparseable_photos_are_not_sent(): void
    {
        config(['services.n8n.app_url' => 'http://app.internal:8080/']);
        [, , $run] = $this->extracting(934);
        [$asset] = $this->startedAssets($run);
        $this->assertStringStartsWith("http://app.internal:8080/api/webhooks/internal/n8n/runs/{$run->public_id}/assets/{$asset['id']}?expires=", $asset['download_url']);
        $envelope = json_decode(Http::recorded()->last()[0]->body(), true);
        $this->assertSame('http://app.internal:8080/api/webhooks/internal/n8n/runs', $envelope['callback_url']);

        // A stored object the stripper cannot parse is refused, never sent as it is.
        $stored = Asset::query()->where('public_id', $asset['id'])->sole();
        $key = app(PrivateAssetStore::class)->put(Workspace::query()->findOrFail($stored->workspace_id), $stored->public_id, "\xFF\xD8\x00not-a-jpeg");
        DB::table('assets')->where('id', $stored->id)->update(['storage_key' => $key]);
        $path = (string) parse_url($asset['download_url'], PHP_URL_PATH).'?'.parse_url($asset['download_url'], PHP_URL_QUERY);
        $this->get($path)->assertStatus(422)->assertJsonPath('error.code', 'ASSET_METADATA_UNPARSEABLE');
    }

    public function test_the_output_schema_file_matches_the_validator(): void
    {
        $schema = json_decode((string) file_get_contents(base_path('n8n/schemas/product-extraction.output.v1.json')), true);
        $this->assertEqualsCanonicalizing(ProductExtractionOutputValidator::ROOT_KEYS, array_keys($schema['properties']));
        $this->assertEqualsCanonicalizing(ProductExtractionOutputValidator::USAGE_KEYS, array_keys($schema['properties']['usage']['properties']));
        $this->assertEqualsCanonicalizing(ProductExtractionOutputValidator::CANDIDATE_KEYS, array_keys($schema['properties']['candidates']['items']['properties']));
        $this->assertSame(ProductExtractionOutputValidator::SCHEMA_VERSION, $schema['properties']['schema_version']['const']);
    }
}
