<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Identity\RegisterTelegramUserAction;
use App\Data\Telegram\TelegramUserData;
use App\Models\ApiIdempotencyRecord;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceAsset;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\ProductIntake\ExtractionInputSelector;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\OggFixture;
use Tests\TestCase;

/** Voice intake stores checked source audio, never a transcript or an AI result. */
final class VoiceIntakeTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(voiceIntake: true);
        config(['karmandeman.voice.intake_enabled' => true]);
        Storage::fake('assets');
    }

    private function seller(int $id, bool $consent = true): User
    {
        $user = app(RegisterTelegramUserAction::class)->execute(new TelegramUserData($id));
        if ($consent) {
            $this->grantPilotConsent($user);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Voice batch'], ['Idempotency-Key' => 'new-project-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function upload(string $project, string $bytes, ?string $key = null): TestResponse
    {
        return $this->post("/api/v1/projects/{$project}/voices", [
            'file' => UploadedFile::fake()->createWithContent('voice.ogg', $bytes),
        ], ['Idempotency-Key' => $key ?? 'upload-voice-'.++$this->keys, 'Accept' => 'application/json']);
    }

    public function test_audio_is_deduplicated_and_stored_encrypted_without_starting_a_model_run(): void
    {
        $user = $this->seller(6110);
        $project = $this->project();
        $bytes = OggFixture::make();
        $response = $this->upload($project, $bytes, 'voice-key-1')->assertCreated()
            ->assertJsonPath('data.mime', 'audio/ogg')->assertJsonPath('data.duration_ms', 200)
            ->assertJsonPath('data.size_bytes', strlen($bytes))->assertJsonPath('data.source', 'api_upload');
        $id = $response->json('data.id');
        $this->assertSame(['id', 'mime', 'size_bytes', 'duration_ms', 'source', 'created_at'], array_keys($response->json('data')));
        $voice = VoiceAsset::query()->sole();
        $this->assertSame(app(WorkspaceRepositoryInterface::class)->currentFor($user)->id, $voice->workspace_id);
        $ciphertext = Storage::disk('assets')->get($voice->storage_key);
        $this->assertStringStartsWith('KMB1', $ciphertext);
        $this->assertStringNotContainsString(substr($bytes, -40), $ciphertext);
        $row = json_encode(DB::table('voice_assets')->sole(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(hash('sha256', $bytes), $row);
        $this->assertStringNotContainsString('voice.ogg', $row);
        $this->get("/api/v1/voices/{$id}/download")->assertOk()
            ->assertHeader('Content-Type', 'audio/ogg')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$id.'.ogg"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($bytes, $this->get("/api/v1/voices/{$id}/download")->getContent());
        $this->getJson("/api/v1/projects/{$project}/voices")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->upload($project, $bytes, 'voice-key-1')->assertOk()->assertJsonPath('data.id', $id);
        $this->upload($project, $bytes, 'voice-key-2')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('voice_assets', 1);
        $this->assertDatabaseCount('ai_workflow_runs', 0);
        $this->assertSame(0, DB::table('credit_ledger')->where('type', 'capture')->count());
        $this->assertSame(2, (int) DB::table('projects')->where('public_id', $project)->value('version'));
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.activity.voices', 1);

        $this->postJson("/api/v1/projects/{$project}/cancel", ['version' => 2], ['Idempotency-Key' => 'cancel-voice'])->assertOk();
        $this->upload($project, OggFixture::withPageEndingNoPacket())
            ->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_ACCEPTING_INPUT');
        $this->assertDatabaseCount('voice_assets', 1);
    }

    public function test_voice_selection_is_tenant_scoped_and_excludes_only_successfully_processed_voices(): void
    {
        $seller = $this->seller(6117);
        $projectId = $this->project();
        $first = $this->upload($projectId, OggFixture::make())->assertCreated()->json('data.id');
        $second = $this->upload($projectId, OggFixture::withLongTags())->assertCreated()->json('data.id');
        $otherProjectId = $this->project();
        $this->upload($otherProjectId, OggFixture::withPageEndingNoPacket())->assertCreated();
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($seller);
        $project = Project::query()->where('public_id', $projectId)->firstOrFail();
        $selector = app(ExtractionInputSelector::class);
        $ids = fn (): array => array_map(fn (VoiceAsset $voice): string => $voice->public_id, $selector->voices($workspace, $project));
        $this->assertSame([$first, $second], $ids());
        $this->assertDatabaseCount('ai_workflow_runs', 0);

        // A failed run may be retried; only an accepted result consumes its inputs.
        $runs = app(AiWorkflowRunRepositoryInterface::class);
        $failed = $runs->create($project, $seller, 'product_extraction', 'product-extraction.openai.v2', [], CarbonImmutable::now()->addMinutes(15));
        $failed->forceFill(['input_voice_ids' => [$first], 'status' => 'failed'])->save();
        $this->assertSame([$first, $second], $ids());
        $succeeded = $runs->create($project, $seller, 'product_extraction', 'product-extraction.openai.v2', [], CarbonImmutable::now()->addMinutes(15));
        $succeeded->forceFill(['input_voice_ids' => [$first], 'status' => 'succeeded'])->save();
        $this->assertSame([$second], $ids());

        $foreignUser = $this->seller(6118);
        $foreignWorkspace = app(WorkspaceRepositoryInterface::class)->currentFor($foreignUser);
        try {
            $selector->voices($foreignWorkspace, $project);
            $this->fail('A foreign workspace selected the seller voice.');
        } catch (DomainException $e) {
            $this->assertSame('PROJECT_NOT_FOUND', $e->getMessage());
        }
        $this->assertSame([], $selector->voices($foreignWorkspace, Project::query()->where('public_id', $this->project())->firstOrFail()));
    }

    public function test_invalid_replayed_or_unconsented_audio_has_no_partial_object(): void
    {
        $user = $this->seller(6111);
        $project = $this->project();
        $this->upload($project, 'not an ogg', 'voice-key-3')->assertStatus(422)->assertJsonPath('error.code', 'VOICE_UNSUPPORTED');
        $this->upload($project, substr(OggFixture::make(), 0, -1), 'voice-key-4')
            ->assertStatus(422)->assertJsonPath('error.code', 'VOICE_DAMAGED');
        config(['karmandeman.voice.max_seconds' => 0]);
        $this->upload($project, OggFixture::make(), 'voice-key-5')->assertStatus(422)->assertJsonPath('error.code', 'VOICE_TOO_LONG');
        config(['karmandeman.voice.max_seconds' => 120]);
        $this->upload($project, OggFixture::make(), 'voice-key-6')->assertCreated();
        $otherProject = $this->project();
        $this->upload($otherProject, OggFixture::make(), 'voice-key-6')
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        $this->useApprovedPilotPolicy('changed-policy');
        $this->upload($project, OggFixture::make(), 'voice-key-7')
            ->assertStatus(409)->assertJsonPath('error.code', 'VOICE_INTAKE_DISABLED');
        $this->useApprovedPilotPolicy('voice-policy-v2', voiceIntake: true);
        $this->upload($project, OggFixture::make(), 'voice-key-8')->assertStatus(403);
        $this->assertDatabaseCount('voice_assets', 1);
        $this->assertCount(1, Storage::disk('assets')->allFiles());
        $this->assertSame($user->id, VoiceAsset::query()->sole()->created_by);
    }

    public function test_a_failed_idempotency_record_leaves_no_encrypted_object(): void
    {
        $this->seller(6116);
        $project = $this->project();
        $this->app->extend(ApiIdempotencyRepositoryInterface::class, fn (ApiIdempotencyRepositoryInterface $real) => new class($real) implements ApiIdempotencyRepositoryInterface
        {
            public function __construct(private ApiIdempotencyRepositoryInterface $real) {}

            public function find(Workspace $workspace, int $userId, string $operation, string $key): ?ApiIdempotencyRecord
            {
                return $this->real->find($workspace, $userId, $operation, $key);
            }

            public function record(Workspace $workspace, int $userId, string $operation, string $key, string $hash, array $attributes): void
            {
                throw new RuntimeException('record failed');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->upload($project, OggFixture::make());
            $this->fail('The failed record was not reported.');
        } catch (RuntimeException $e) {
            $this->assertSame('record failed', $e->getMessage());
        }
        $this->assertDatabaseCount('voice_assets', 0);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    public function test_another_workspace_cannot_read_list_or_attach_to_the_sellers_batch(): void
    {
        $this->seller(6112);
        $project = $this->project();
        $id = $this->upload($project, OggFixture::make())->assertCreated()->json('data.id');
        $this->seller(6113);
        $this->getJson("/api/v1/voices/{$id}/download")->assertStatus(404);
        $this->getJson("/api/v1/projects/{$project}/voices")->assertStatus(404);
        $this->upload($project, OggFixture::make())->assertStatus(404);
        $this->assertDatabaseCount('voice_assets', 1);
    }

    public function test_intake_requires_an_explicit_policy_flag_and_is_never_live_in_production(): void
    {
        $this->seller(6114);
        $project = $this->project();
        config(['karmandeman.voice.intake_enabled' => false]);
        $this->upload($project, OggFixture::make())->assertStatus(409)->assertJsonPath('error.code', 'VOICE_INTAKE_DISABLED');

        config(['karmandeman.voice.intake_enabled' => true]);
        $this->useApprovedPilotPolicy('no-voice-policy');
        $this->upload($project, OggFixture::make())->assertStatus(409)->assertJsonPath('error.code', 'VOICE_INTAKE_DISABLED');
        $this->useApprovedPilotPolicy('voice-policy-v2', voiceIntake: true);
        $this->app['env'] = 'production';
        try {
            $this->upload($project, OggFixture::make())->assertStatus(409)->assertJsonPath('error.code', 'VOICE_INTAKE_DISABLED');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('voice_assets', 0);
        $this->assertCount(0, Storage::disk('assets')->allFiles());
    }

    public function test_erasure_removes_voice_rows_and_shared_private_objects(): void
    {
        $user = $this->seller(6115);
        $project = $this->project();
        $this->upload($project, OggFixture::make())->assertCreated();
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        app(PrivateAssetStore::class)->deleteWorkspace($workspace);
        DB::transaction(fn () => app(AccountErasureRepositoryInterface::class)->purge($workspace, $user, []));

        $this->assertDatabaseCount('voice_assets', 0);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }
}
