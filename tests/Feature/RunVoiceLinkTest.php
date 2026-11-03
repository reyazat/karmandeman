<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AI\DispatchProductExtraction;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Services\AI\AIWorkflowService;
use App\Services\AI\RunAssetUrlSigner;
use App\Services\AI\WorkflowSignature;
use App\Services\Storage\OggOpusInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\Fakes\OggFixture;
use Tests\TestCase;

/** n8n reads a run's voice only through its own signed link, under the photo rules (ADR-047). */
final class RunVoiceLinkTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CALLBACK_SECRET = 'test-callback-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(aiProviders: ['openai'], voiceIntake: true);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => 'test-workflow-token',
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'services.n8n.extraction_webhook_path' => AIWorkflowService::OPENAI_WEBHOOK_PATH,
            'karmandeman.ai.extraction_enabled' => true,
            'karmandeman.ai.workflow_version' => AIWorkflowService::OPENAI_WORKFLOW_VERSION,
            'karmandeman.voice.intake_enabled' => true,
            'karmandeman.gift_credits' => 100,
        ]);
        Http::fake(['*' => Http::response(['ok' => true])]);
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

    private function upload(string $project, string $kind, string $name, string $bytes): string
    {
        return $this->post("/api/v1/projects/{$project}/{$kind}", [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Idempotency-Key' => 'upload-key-'.++$this->keys, 'Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    /** A claimed run of a batch with one photo and two voices, the first of them given to the run. */
    private function claimedRun(int $telegramId): array
    {
        $this->seller($telegramId);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-key-'.++$this->keys])->assertCreated()->json('data.id');
        $this->upload($project, 'assets', 'photo.jpg', ImageFixture::make($telegramId));
        $voices = [
            $this->upload($project, 'voices', 'voice.ogg', OggFixture::withLongTags()),
            $this->upload($project, 'voices', 'other.ogg', OggFixture::make()),
        ];
        $version = (int) $this->getJson("/api/v1/projects/{$project}")->json('data.version');
        $quote = $this->postJson("/api/v1/projects/{$project}/extraction-quotes", ['version' => $version], ['Idempotency-Key' => 'quote-key-'.++$this->keys])->assertCreated();
        $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $version, 'quote_id' => $quote->json('data.id')], ['Idempotency-Key' => 'finish-key-'.++$this->keys])->assertStatus(202);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();
        // Runs do not choose voices yet; the next slice fills this from the batch (ADR-047).
        $run->forceFill(['input_voice_ids' => [$voices[0]]])->save();
        $url = app(RunAssetUrlSigner::class)->voiceUrl($run, $voices[0]);
        $this->get($url)->assertStatus(403)->assertJsonPath('error.code', 'RUN_VOICE_FORBIDDEN');
        $this->signed('/api/webhooks/internal/n8n/runs/claim', [
            'schema_version' => 'product-extraction.claim.v1', 'run_id' => $run->public_id, 'execution_id' => 'exec-1',
        ])->assertOk();

        return [$run->fresh(), $voices, $url];
    }

    private function signed(string $uri, array $body): TestResponse
    {
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars([
            WorkflowSignature::HEADER_TIMESTAMP => (string) $timestamp,
            WorkflowSignature::HEADER_SIGNATURE => (new WorkflowSignature)->sign(self::CALLBACK_SECRET, $timestamp, $raw),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $raw);
    }

    public function test_n8n_reads_a_claimed_run_voice_without_its_comment_header(): void
    {
        [, , $url] = $this->claimedRun(7101);
        $this->assertStringContainsString('/api/webhooks/internal/n8n/runs/', $url);

        $response = $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'audio/ogg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $bytes = (string) $response->getContent();
        $this->assertStringNotContainsString('Seller private note', $bytes);
        $this->assertStringNotContainsString('PhoneRecorder', $bytes);
        $this->assertSame(200, app(OggOpusInspector::class)->inspect($bytes)['duration_ms']);
    }

    public function test_a_voice_link_works_only_for_its_run_voice_while_voice_intake_is_allowed(): void
    {
        [$run, $voices, $url] = $this->claimedRun(7102);
        $forbidden = fn (string $link) => $this->get($link)->assertStatus(403)->assertJsonPath('error.code', 'RUN_VOICE_FORBIDDEN');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $forbidden(str_replace($query['signature'], str_repeat('0', 64), $url));
        $forbidden(str_replace('expires='.$query['expires'], 'expires='.($query['expires'] + 3600), $url));
        $forbidden(strtok($url, '?'));
        $forbidden(str_replace('signature=', 'signature[]=', $url));
        // A voice of the batch the run was not given, even with a link signed for it.
        $forbidden(app(RunAssetUrlSigner::class)->voiceUrl($run, $voices[1]));
        // A photo link's signature is no voice link, and a voice link is no photo link.
        $forbidden(str_replace('/assets/', '/voices/', app(RunAssetUrlSigner::class)->url($run, $voices[0])));
        $this->get(str_replace('/voices/', '/assets/', $url))->assertStatus(403)->assertJsonPath('error.code', 'RUN_ASSET_FORBIDDEN');

        // Voice intake switched off, or a policy that no longer declares voice: nothing leaves.
        config(['karmandeman.voice.intake_enabled' => false]);
        $forbidden($url);
        config(['karmandeman.voice.intake_enabled' => true]);
        $this->get($url)->assertOk();

        // Erasure, and then the deadline, end the link.
        DB::table('workspaces')->where('id', $run->workspace_id)->update(['status' => 'deletion_pending']);
        $forbidden($url);
        DB::table('workspaces')->where('id', $run->workspace_id)->update(['status' => 'active']);
        $this->get($url)->assertOk();
        $this->travel(16)->minutes();
        $forbidden($url);
    }

    public function test_a_run_with_its_result_serves_no_voice(): void
    {
        [$run, , $url] = $this->claimedRun(7103);
        $this->signed('/api/webhooks/internal/n8n/runs', [
            'schema_version' => 'product-extraction.output.v1', 'run_id' => $run->public_id, 'status' => 'succeeded', 'error_code' => null,
            'usage' => ['model_requests' => 1, 'input_tokens' => 10, 'output_tokens' => 5, 'provider' => 'openai', 'model' => 'fixture-1'],
            'candidates' => [],
        ])->assertOk();

        $this->get($url)->assertStatus(403)->assertJsonPath('error.code', 'RUN_VOICE_FORBIDDEN');
    }
}
