<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Jobs\AI\DispatchProductExtraction;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\AI\WorkflowSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\ImageFixture;

/**
 * Drives the bot for a seller whose batch an n8n extraction run filled
 * (ADR-032 to ADR-035): webhook updates, button presses and the bot's replies.
 */
trait DrivesExtractedBatches
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CALLBACK_SECRET = 'test-callback-secret';

    private int $updates = 0;

    /** Telegram, n8n and storage fakes for a seller whose batch an n8n run extracted. */
    private function setUpExtractedBatches(): void
    {
        $this->useApprovedPilotPolicy(aiProviders: ['google']);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => 'test-workflow-token',
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'karmandeman.ai.extraction_enabled' => true,
        ]);
        Http::fake(['*' => Http::response(['ok' => true])]);
        Storage::fake('assets');
        Queue::fake([DispatchProductExtraction::class, SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    private function update(array $payload): TestResponse
    {
        return $this->postJson('/api/webhooks/telegram', ['update_id' => ++$this->updates] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertOk();
    }

    private function text(string $text, int $telegramId): void
    {
        $this->update(['message' => [
            'message_id' => ++$this->updates, 'date' => time(), 'text' => $text,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
        ]]);
    }

    private function press(string $data, int $telegramId): void
    {
        $this->update(['callback_query' => [
            'id' => 'cb-'.++$this->updates, 'data' => $data,
            'from' => ['id' => $telegramId, 'first_name' => 'U'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
        ]]);
    }

    private function seller(int $telegramId): User
    {
        $this->text('/start', $telegramId);
        $user = User::query()->latest('id')->firstOrFail();
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A seller's batch whose extraction run recorded these candidates; every
     * candidate cites the batch's first photo.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{User, string}
     */
    private function extracted(int $telegramId, array $candidates): array
    {
        $user = $this->seller($telegramId);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => "project-{$telegramId}"])
            ->assertCreated()->json('data.id');
        $photo = $this->post("/api/v1/projects/{$project}/assets", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', ImageFixture::make($telegramId)),
        ], ['Idempotency-Key' => "asset-key-{$telegramId}", 'Accept' => 'application/json'])->assertCreated()->json('data.id');
        $version = (int) $this->getJson("/api/v1/projects/{$project}")->json('data.version');
        $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $version], ['Idempotency-Key' => "finish-{$telegramId}"])
            ->assertStatus(202);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();

        $raw = json_encode([
            'schema_version' => 'product-extraction.output.v1',
            'run_id' => $run->public_id,
            'status' => 'succeeded',
            'error_code' => null,
            'usage' => ['model_requests' => 1, 'input_tokens' => 10, 'output_tokens' => 10, 'provider' => 'fixture', 'model' => 'fixture-1'],
            'candidates' => array_map(fn (array $candidate): array => $candidate + ['source_asset_ids' => [$photo]], $candidates),
        ], JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $this->call('POST', '/api/webhooks/internal/n8n/runs', [], [], [], $this->transformHeadersToServerVars([
            WorkflowSignature::HEADER_TIMESTAMP => (string) $timestamp,
            WorkflowSignature::HEADER_SIGNATURE => (new WorkflowSignature)->sign(self::CALLBACK_SECRET, $timestamp, $raw),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $raw)->assertOk()->assertJsonPath('data.outcome', 'recorded');

        return [$user, $photo];
    }

    /** @return array<string, mixed> */
    private function candidate(?string $name, ?array $price = ['amount' => 120000, 'unit' => 'IRT'], ?string $description = null): array
    {
        return [
            'name' => $name, 'sku' => 'BAG-1', 'description_fa' => $description, 'price' => $price,
            'attributes' => [['name' => 'رنگ', 'values' => ['مشکی', 'قهوه‌ای']], ['name' => 'جنس', 'values' => null]],
            'category_path' => ['کیف', 'زنانه'],
        ];
    }

    /** The last bot message containing this text. */
    private function sent(string $fragment): SendTelegramMessage
    {
        $job = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, "No bot message contains «{$fragment}».");

        return $job;
    }

    /** @return list<string> */
    private function buttons(SendTelegramMessage $job): array
    {
        return array_merge(...array_map(fn (array $row): array => array_column($row, 'callback_data'), $job->buttons));
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, int $revision): array
    {
        return ProductRevision::query()->where('product_id', $product->id)->where('revision', $revision)->sole()->payload;
    }
}
