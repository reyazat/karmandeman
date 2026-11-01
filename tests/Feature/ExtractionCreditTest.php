<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Enums\CreditLedgerType;
use App\Jobs\AI\DispatchProductExtraction;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\CreditLedgerEntry;
use App\Models\CreditQuote;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Services\AI\WorkflowSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Step 5 slice: an extraction run is quoted, held, then spent or released (ADR-039). */
class ExtractionCreditTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CALLBACK_SECRET = 'test-callback-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(aiProviders: ['google']);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => 'test-workflow-token',
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'karmandeman.ai.extraction_enabled' => true,
            'karmandeman.gift_credits' => 10,
            'karmandeman.credits.extraction_per_photo' => 3,
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

    /** A batch of this many photos. */
    private function batch(int $photos, int $seed): string
    {
        $project = $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-key-'.++$this->keys])
            ->assertCreated()->json('data.id');
        for ($i = 0; $i < $photos; $i++) {
            $this->photo($project, $seed + $i);
        }

        return $project;
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

    private function quote(string $project, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/extraction-quotes", ['version' => $this->version($project)], ['Idempotency-Key' => $key ?? 'quote-key-'.++$this->keys]);
    }

    private function accept(string $project, ?string $quote, ?int $version = null, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $version ?? $this->version($project), 'quote_id' => $quote], ['Idempotency-Key' => $key ?? 'finish-key-'.++$this->keys]);
    }

    /** @return array{int, int} balance and reserved of the seller's wallet */
    private function wallet(): array
    {
        $wallet = Wallet::query()->latest('id')->firstOrFail();

        return [$wallet->balance_cached, $wallet->reserved_cached];
    }

    private function state(string $project): string
    {
        return (string) DB::table('projects')->where('public_id', $project)->value('state');
    }

    private function runCallback(AiWorkflowRun $run, array $overrides = []): TestResponse
    {
        return $this->signed('/api/webhooks/internal/n8n/runs', array_replace([
            'schema_version' => 'product-extraction.output.v1', 'run_id' => $run->public_id, 'status' => 'succeeded', 'error_code' => null,
            'usage' => ['model_requests' => 1, 'input_tokens' => 10, 'output_tokens' => 10, 'provider' => 'fixture', 'model' => 'fixture-1'],
            'candidates' => [[
                'name' => 'کیف', 'sku' => null, 'description_fa' => null, 'price' => null, 'attributes' => null, 'category_path' => null,
                'source_asset_ids' => [$run->input_asset_ids[0]],
            ]],
        ], $overrides));
    }

    /** @param  array<string, mixed>  $body */
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

    /** Every cache equals the sum of its ledger column (ADR-039). */
    private function assertLedgerReconciles(): void
    {
        foreach (Wallet::query()->get() as $wallet) {
            $this->assertSame((int) CreditLedgerEntry::query()->where('wallet_id', $wallet->id)->sum('amount'), $wallet->balance_cached);
            $this->assertSame((int) CreditLedgerEntry::query()->where('wallet_id', $wallet->id)->sum('held_amount'), $wallet->reserved_cached);
            $this->assertGreaterThanOrEqual($wallet->reserved_cached, $wallet->balance_cached);
        }
    }

    public function test_a_quote_prices_the_new_photos_and_holds_nothing(): void
    {
        $this->seller(5001);
        $project = $this->batch(2, 1);

        $quote = $this->quote($project, 'quote-once-1')->assertCreated()
            ->assertJsonPath('data.credits_total', 6)
            ->assertJsonPath('data.lines.0', ['type' => 'product_extraction_photo', 'quantity' => 2, 'unit_credits' => 3, 'credits' => 6])
            ->assertJsonPath('data.increase_cap_credits', 0)
            ->assertJsonPath('data.available_credits', 10)
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.tariff_version', 'extraction-v1')
            ->json('data');
        $this->assertCount(2, $quote['photo_ids']);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), strtotime($quote['expires_at']));
        // A replay answers the same quote; nothing was held or started.
        $this->quote($project, 'quote-once-1')->assertOk()->assertJsonPath('data.id', $quote['id']);
        $this->assertSame(1, CreditQuote::query()->count());
        $this->assertSame([10, 0], $this->wallet());
        $this->assertSame('collecting_input', $this->state($project));

        // finish-input without the quote refuses to start paid work.
        $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $this->version($project)], ['Idempotency-Key' => 'finish-no-quote'])
            ->assertStatus(409)->assertJsonPath('error.code', 'EXTRACTION_QUOTE_REQUIRED');
        $this->assertSame(0, AiWorkflowRun::query()->count());
    }

    public function test_without_new_photos_or_extraction_there_is_nothing_to_quote(): void
    {
        $this->seller(5002);
        $empty = $this->batch(0, 1);
        $this->quote($empty)->assertStatus(409)->assertJsonPath('error.code', 'EXTRACTION_NOT_NEEDED');

        config(['karmandeman.ai.extraction_enabled' => false]);
        $photos = $this->batch(1, 2);
        $this->quote($photos)->assertStatus(409)->assertJsonPath('error.code', 'EXTRACTION_NOT_NEEDED');
        $this->assertSame(0, CreditQuote::query()->count());
    }

    public function test_accepting_holds_the_quote_and_success_spends_it_once(): void
    {
        $this->seller(5003);
        $project = $this->batch(2, 1);
        $quote = $this->quote($project)->json('data.id');

        $this->accept($project, $quote)->assertStatus(202)->assertJsonPath('data.state', 'extracting');

        $run = AiWorkflowRun::query()->sole();
        $this->assertSame([10, 6], $this->wallet());
        $hold = CreditLedgerEntry::query()->findOrFail($run->reservation_entry_id);
        $this->assertSame(CreditLedgerType::Reserve, $hold->type);
        $this->assertSame(['ai_workflow_run', $run->id, 0, 6], [$hold->related_type, $hold->related_id, $hold->amount, $hold->held_amount]);
        $this->assertSame(CreditQuote::query()->sole()->id, $run->quote_id);
        $this->getJson('/api/v1/wallet/ledger')->assertOk()
            ->assertJsonPath('data.balance', 10)->assertJsonPath('data.reserved', 6)->assertJsonPath('data.available', 4)
            ->assertJsonPath('data.entries.0.type', 'reserve')->assertJsonPath('data.entries.0.held_amount', 6);

        $this->runCallback($run)->assertOk()->assertJsonPath('data.outcome', 'recorded');
        $this->assertSame([4, 0], $this->wallet());
        $capture = CreditLedgerEntry::query()->where('type', 'capture')->sole();
        $this->assertSame(['ai_workflow_run', $run->id, -6, -6], [$capture->related_type, $capture->related_id, $capture->amount, $capture->held_amount]);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '6 اعتبار از موجودی کسر شد'));

        // The same callback again is answered and spends nothing more.
        $this->runCallback($run)->assertOk()->assertJsonPath('data.outcome', 'already_recorded');
        $this->assertSame([4, 0], $this->wallet());
        $this->assertSame(3, CreditLedgerEntry::query()->count());
        $this->assertLedgerReconciles();
    }

    public function test_failure_timeout_cancel_and_a_late_callback_release_without_charge(): void
    {
        $this->seller(5004);

        // Reported failure.
        $failed = $this->batch(1, 1);
        $this->accept($failed, $this->quote($failed)->json('data.id'))->assertStatus(202);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->runCallback($run, ['status' => 'failed', 'error_code' => 'MODEL_BLOCKED', 'candidates' => []])->assertOk();
        $this->assertSame([10, 0], $this->wallet());
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'اعتبار رزروشده برگشت'));

        // Timeout: the sweep releases, and the late callback spends nothing.
        $late = $this->batch(1, 2);
        $this->accept($late, $this->quote($late)->json('data.id'))->assertStatus(202);
        $lateRun = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->travel(16)->minutes();
        $this->artisan('ai:expire-runs')->assertSuccessful();
        $this->assertSame([10, 0], $this->wallet());
        $this->runCallback($lateRun)->assertOk()->assertJsonPath('data.outcome', 'discarded');
        $this->assertSame([10, 0], $this->wallet());
        $this->travelBack();

        // Cancel releases (FR-063).
        $cancelled = $this->batch(1, 3);
        $this->accept($cancelled, $this->quote($cancelled)->json('data.id'))->assertStatus(202);
        $this->assertSame([10, 3], $this->wallet());
        $this->postJson("/api/v1/projects/{$cancelled}/cancel", ['version' => $this->version($cancelled)], ['Idempotency-Key' => 'cancel-held'])->assertOk();
        $this->assertSame([10, 0], $this->wallet());

        // Each hold was settled exactly once, by a release.
        $this->assertSame(3, CreditLedgerEntry::query()->where('type', 'reserve')->count());
        $this->assertSame(3, CreditLedgerEntry::query()->where('type', 'release')->count());
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', 'capture')->count());
        $this->assertLedgerReconciles();
    }

    public function test_every_other_way_a_run_ends_releases_its_hold(): void
    {
        $this->seller(5009);
        $started = function (int $seed): AiWorkflowRun {
            $project = $this->batch(1, $seed);
            $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);

            return AiWorkflowRun::query()->latest('id')->firstOrFail();
        };

        // A signed body that breaks the schema.
        $this->runCallback($started(1), ['candidates' => 'nope'])->assertStatus(422);
        // Reported usage over the run caps.
        $this->runCallback($started(2), ['usage' => ['model_requests' => 999, 'input_tokens' => 1, 'output_tokens' => 1, 'provider' => 'fixture', 'model' => 'fixture-1']])->assertOk();
        // A callback after the deadline, before the sweep.
        $late = $started(3);
        $this->travel(16)->minutes();
        $this->runCallback($late)->assertOk()->assertJsonPath('data.outcome', 'discarded');
        $this->travelBack();
        // The start job ran out of retries.
        (new DispatchProductExtraction($started(4)->id))->failed(null);
        // The claim finds extraction switched off.
        $claimed = $started(5);
        config(['karmandeman.ai.extraction_enabled' => false]);
        $this->signed('/api/webhooks/internal/n8n/runs/claim', [
            'schema_version' => 'product-extraction.claim.v1', 'run_id' => $claimed->public_id, 'execution_id' => 'exec-1',
        ])->assertStatus(409);

        $this->assertSame(
            ['OUTPUT_INVALID', 'USAGE_OVER_CAP', 'TIMED_OUT', 'DISPATCH_FAILED', 'EXTRACTION_DISABLED'],
            AiWorkflowRun::query()->orderBy('id')->pluck('error_code')->all(),
        );
        $this->assertSame([10, 0], $this->wallet());
        $this->assertSame(5, CreditLedgerEntry::query()->where('type', 'release')->count());
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', 'capture')->count());
        $this->assertLedgerReconciles();
    }

    public function test_a_quote_for_a_run_no_longer_allowed_is_refused_and_replays_show_acceptance(): void
    {
        $user = $this->seller(5010);
        $this->completeOnboarding($user);
        $project = $this->batch(1, 1);
        $this->botText('/done', 5010);
        $offer = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->buttons !== [])->last();
        $quote = CreditQuote::query()->sole()->public_id;

        // Extraction stops before the press: nothing is reserved and the seller is told why.
        config(['karmandeman.ai.extraction_enabled' => false]);
        $this->botPress($offer->buttons[0][0]['callback_data'], 5010, 'cb-off');
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'پس از این پیش‌فاکتور تغییر کرده است'));
        $this->accept($project, $quote)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
        $this->assertSame('collecting_input', $this->state($project));
        $this->assertSame([10, 0], $this->wallet());

        // Back on: a replay of the quote key reflects that it was accepted since.
        config(['karmandeman.ai.extraction_enabled' => true]);
        $second = $this->batch(1, 2);
        $replay = fn (): TestResponse => $this->postJson("/api/v1/projects/{$second}/extraction-quotes", ['version' => 2], ['Idempotency-Key' => 'quote-replayed']);
        $replay()->assertCreated()->assertJsonPath('data.accepted', false);
        $this->accept($second, CreditQuote::query()->latest('id')->firstOrFail()->public_id)->assertStatus(202);
        $replay()->assertOk()->assertJsonPath('data.accepted', true);
    }

    public function test_purge_removes_held_runs_quotes_and_the_ledger(): void
    {
        $user = $this->seller(5011);
        $project = $this->batch(1, 1);
        $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);

        DB::transaction(fn () => app(AccountErasureRepositoryInterface::class)->purge($workspace, $user, []));

        foreach (['wallets', 'credit_quotes', 'ai_workflow_runs', 'projects'] as $table) {
            $this->assertSame(0, DB::table($table)->where('workspace_id', $workspace->id)->count(), $table);
        }
        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_without_enough_credit_nothing_is_held_or_started(): void
    {
        config(['karmandeman.gift_credits' => 5]);
        $this->seller(5005);
        $project = $this->batch(2, 1);
        $quote = $this->quote($project)->assertCreated()->assertJsonPath('data.available_credits', 5)->json('data.id');

        $this->accept($project, $quote)->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_CREDITS');

        $this->assertSame('collecting_input', $this->state($project));
        $this->assertSame(0, AiWorkflowRun::query()->count());
        $this->assertSame([5, 0], $this->wallet());
        $this->assertNull(CreditQuote::query()->sole()->accepted_at);
        Queue::assertNotPushed(DispatchProductExtraction::class);
    }

    public function test_an_expired_stale_used_or_foreign_quote_starts_nothing(): void
    {
        $other = $this->seller(5006);
        $foreignProject = $this->batch(1, 90);
        $foreign = $this->quote($foreignProject)->json('data.id');

        $this->seller(5007);
        $project = $this->batch(1, 1);
        $this->accept($project, $foreign)->assertNotFound()->assertJsonPath('error.code', 'QUOTE_NOT_FOUND');
        $this->accept($project, '00000000-0000-4000-8000-000000000000')->assertNotFound();

        $expired = $this->quote($project)->json('data.id');
        $this->travel(16)->minutes();
        $this->accept($project, $expired)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
        $this->travelBack();

        // A photo sent after the quote changes what would be read.
        $stale = $this->quote($project)->json('data.id');
        $staleVersion = $this->version($project);
        $this->photo($project, 2);
        $this->accept($project, $stale)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_STALE');
        $this->accept($project, $stale, $staleVersion)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_VERSION_CONFLICT');

        $this->assertSame(0, AiWorkflowRun::query()->count());
        $this->assertSame(0, (int) Wallet::query()->sum('reserved_cached'));

        // A used quote never starts a second run, even after the batch reopens.
        $fresh = $this->quote($project)->json('data.id');
        $this->accept($project, $fresh)->assertStatus(202);
        $run = AiWorkflowRun::query()->sole();
        $this->runCallback($run, ['status' => 'failed', 'error_code' => 'MODEL_BLOCKED', 'candidates' => []])->assertOk();
        $this->assertSame('collecting_input', $this->state($project));
        $this->accept($project, $fresh)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_ALREADY_USED');
        $this->assertSame(1, AiWorkflowRun::query()->count());
        $this->assertSame($other->id, CreditQuote::query()->where('public_id', $foreign)->sole()->created_by);
        $this->assertLedgerReconciles();
    }

    public function test_the_bot_offers_no_accept_button_without_enough_credit_and_balance_shows_holds(): void
    {
        config(['karmandeman.gift_credits' => 2]);
        $user = $this->seller(5008);
        $this->completeOnboarding($user);
        $this->batch(1, 1);

        $this->botText('/done', 5008);
        $job = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '3 اعتبار لازم است'))->last();
        $this->assertNotNull($job);
        $this->assertStringContainsString('اعتبار کافی نیست', $job->text);
        $this->assertSame([], $job->buttons);

        app(WalletRepositoryInterface::class)->appendEntry(Wallet::query()->sole(), CreditLedgerType::Grant, 3, 'test-top-up');
        $this->botText('/done', 5008);
        $offer = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->buttons !== [] && str_starts_with($job->buttons[0][0]['callback_data'], 'quote:accept:'))->last();
        $this->assertNotNull($offer);
        $this->botPress($offer->buttons[0][0]['callback_data'], 5008, 'cb-one');
        $this->assertSame([5, 3], $this->wallet());
        // The same press again replays, and holds nothing more.
        $this->botPress($offer->buttons[0][0]['callback_data'], 5008, 'cb-one');
        $this->assertSame([5, 3], $this->wallet());
        $this->assertSame(1, AiWorkflowRun::query()->count());

        $this->botText('/balance', 5008);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '5 اعتبار، که 3 اعتبار آن'));
    }

    private function completeOnboarding(User $user): void
    {
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        $onboarding = app(AdvanceTelegramOnboardingAction::class);
        $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
        foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
            $onboarding->execute($workspace, $user, ...$step);
        }
    }

    private function botText(string $text, int $telegramId): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'message_id' => random_int(2, 10_000_000), 'date' => time(), 'text' => $text,
                'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function botPress(string $data, int $telegramId, string $callbackId): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => $callbackId, 'data' => $data, 'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }
}
