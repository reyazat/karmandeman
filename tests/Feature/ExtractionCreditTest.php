<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Actions\Wallet\ReconcileCreditLedgerAction;
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
use App\Services\AI\AIWorkflowService;
use App\Services\AI\WorkflowSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Step 5: an extraction run is quoted, its cap held, then its real usage spent or the hold released (ADR-039, ADR-042). */
class ExtractionCreditTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CALLBACK_SECRET = 'test-callback-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(aiProviders: ['openai']);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => 'test-workflow-token',
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'services.n8n.extraction_webhook_path' => AIWorkflowService::OPENAI_WEBHOOK_PATH,
            'karmandeman.ai.extraction_enabled' => true,
            'karmandeman.ai.workflow_version' => AIWorkflowService::OPENAI_WORKFLOW_VERSION,
            'karmandeman.gift_credits' => 10,
            'karmandeman.credits.extraction_per_photo' => 3,
            'karmandeman.credits.quote_cap_percent' => 50,
            'karmandeman.credits.minimum.file_to_text' => 1,
            // The callback fixture's 10 input and 10 output tokens cost 1 + 2 = 3 credits.
            'karmandeman.credits.rates' => ['input_tokens_per_million' => 100_000, 'output_tokens_per_million' => 200_000, 'model_request' => 0],
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
            'usage' => ['model_requests' => 1, 'input_tokens' => 10, 'output_tokens' => 10, 'provider' => 'openai', 'model' => 'fixture-1'],
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
            ->assertJsonPath('data.increase_cap_credits', 3)
            ->assertJsonPath('data.cap_credits', 9)
            ->assertJsonPath('data.available_credits', 10)
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.tariff_version', 'usage-v1')
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

    public function test_accepting_holds_the_cap_and_success_spends_the_real_usage_once(): void
    {
        $this->seller(5003);
        $project = $this->batch(2, 1);
        $quote = $this->quote($project)->json('data.id');

        $this->accept($project, $quote)->assertStatus(202)->assertJsonPath('data.state', 'extracting');

        $run = AiWorkflowRun::query()->sole();
        $this->assertSame([10, 9], $this->wallet());
        $hold = CreditLedgerEntry::query()->findOrFail($run->reservation_entry_id);
        $this->assertSame(CreditLedgerType::Reserve, $hold->type);
        $this->assertSame(['ai_workflow_run', $run->id, 0, 9], [$hold->related_type, $hold->related_id, $hold->amount, $hold->held_amount]);
        $this->assertSame(CreditQuote::query()->sole()->id, $run->quote_id);
        $this->getJson('/api/v1/wallet/ledger')->assertOk()
            ->assertJsonPath('data.balance', 10)->assertJsonPath('data.reserved', 9)->assertJsonPath('data.available', 1)
            ->assertJsonPath('data.entries.0.type', 'reserve')->assertJsonPath('data.entries.0.held_amount', 9);

        // The run's usage costs 3; the rest of the hold is free again in the same entry.
        $this->runCallback($run)->assertOk()->assertJsonPath('data.outcome', 'recorded');
        $this->assertSame([7, 0], $this->wallet());
        $capture = CreditLedgerEntry::query()->where('type', 'capture')->sole();
        $this->assertSame(['ai_workflow_run', $run->id, -3, -9], [$capture->related_type, $capture->related_id, $capture->amount, $capture->held_amount]);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '3 اعتبار از موجودی کسر شد'));

        // The same callback again is answered and spends nothing more.
        $this->runCallback($run)->assertOk()->assertJsonPath('data.outcome', 'already_recorded');
        $this->assertSame([7, 0], $this->wallet());
        $this->assertSame(3, CreditLedgerEntry::query()->count());
        $this->assertLedgerReconciles();
    }

    public function test_usage_above_the_cap_charges_only_the_cap_and_is_logged(): void
    {
        $this->seller(5012);
        $project = $this->batch(1, 1);
        $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);
        $run = AiWorkflowRun::query()->sole();
        $this->assertSame([10, 5], $this->wallet());
        Log::spy();

        // 300k input tokens are inside the run caps but cost far more than the accepted cap.
        $this->runCallback($run, ['usage' => ['model_requests' => 1, 'input_tokens' => 300_000, 'output_tokens' => 10, 'provider' => 'openai', 'model' => 'fixture-1']])
            ->assertOk()->assertJsonPath('data.outcome', 'recorded');

        $this->assertSame([5, 0], $this->wallet());
        $this->assertSame([-5, -5], [CreditLedgerEntry::query()->where('type', 'capture')->sole()->amount, CreditLedgerEntry::query()->where('type', 'capture')->sole()->held_amount]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'credit.charge_capped'
            && $context === ['run_id' => $run->public_id, 'charge' => 30_002, 'cap' => 5])->once();
        $this->assertLedgerReconciles();
    }

    public function test_the_class_minimum_tops_up_a_small_estimate_and_a_small_charge(): void
    {
        config([
            'karmandeman.credits.extraction_per_photo' => 1,
            'karmandeman.credits.minimum.file_to_text' => 4,
            'karmandeman.credits.rates' => ['input_tokens_per_million' => 0, 'output_tokens_per_million' => 0, 'model_request' => 0],
        ]);
        $this->seller(5013);
        $project = $this->batch(1, 1);

        $quote = $this->quote($project)->assertCreated()
            ->assertJsonPath('data.lines.1', ['type' => 'output_class_minimum', 'quantity' => 1, 'unit_credits' => 3, 'credits' => 3])
            ->assertJsonPath('data.credits_total', 4)
            ->assertJsonPath('data.cap_credits', 6)
            ->json('data.id');
        $this->accept($project, $quote)->assertStatus(202);
        $this->assertSame([10, 6], $this->wallet());

        $this->runCallback(AiWorkflowRun::query()->sole())->assertOk();
        $this->assertSame([6, 0], $this->wallet());
        $this->assertLedgerReconciles();
    }

    public function test_a_quote_keeps_its_tariff_and_a_fixed_price_quote_is_spent_whole(): void
    {
        $this->seller(5014);

        // Rates raised after acceptance do not reach the accepted quote.
        $first = $this->batch(1, 1);
        $this->accept($first, $this->quote($first)->json('data.id'))->assertStatus(202);
        config(['karmandeman.credits.rates' => ['input_tokens_per_million' => 1_000_000, 'output_tokens_per_million' => 1_000_000, 'model_request' => 5]]);
        $this->runCallback(AiWorkflowRun::query()->latest('id')->firstOrFail())->assertOk();
        $this->assertSame([7, 0], $this->wallet());

        // A fixed-price quote of ADR-039, accepted before the usage tariff, is spent whole.
        $second = $this->batch(1, 2);
        $quote = $this->quote($second)->json('data.id');
        DB::table('credit_quotes')->where('public_id', $quote)->update(['tariff' => null, 'tariff_version' => 'extraction-v1', 'increase_cap_credits' => 0]);
        $this->accept($second, $quote)->assertStatus(202);
        $this->assertSame([7, 3], $this->wallet());
        $this->runCallback(AiWorkflowRun::query()->latest('id')->firstOrFail())->assertOk();
        $this->assertSame([4, 0], $this->wallet());
        $this->assertLedgerReconciles();
    }

    public function test_the_minimum_comes_from_the_quote_and_a_run_without_its_quote_pays_only_the_current_minimum(): void
    {
        config(['karmandeman.credits.rates' => ['input_tokens_per_million' => 0, 'output_tokens_per_million' => 0, 'model_request' => 0]]);
        $this->seller(5016);

        // A minimum raised after acceptance does not reach the accepted quote.
        $first = $this->batch(1, 1);
        $this->accept($first, $this->quote($first)->json('data.id'))->assertStatus(202);
        config(['karmandeman.credits.minimum.file_to_text' => 4]);
        $this->runCallback(AiWorkflowRun::query()->latest('id')->firstOrFail())->assertOk();
        $this->assertSame([9, 0], $this->wallet());

        // A run whose quote is gone pays the current minimum, not the cap, and it is logged.
        $second = $this->batch(1, 2);
        $this->accept($second, $this->quote($second)->json('data.id'))->assertStatus(202);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->assertSame([9, 6], $this->wallet());
        DB::table('ai_workflow_runs')->where('id', $run->id)->update(['quote_id' => null]);
        Log::spy();
        $this->runCallback($run)->assertOk();
        $this->assertSame([5, 0], $this->wallet());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'credit.quote_missing' && $context === ['run_id' => $run->public_id])->once();
        $this->assertLedgerReconciles();
    }

    public function test_the_charge_stands_whether_or_not_the_seller_accepts_the_output(): void
    {
        $this->seller(5015);
        $project = $this->batch(1, 1);
        $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);
        $this->runCallback(AiWorkflowRun::query()->sole())->assertOk();
        $this->assertSame('review_required', $this->state($project));
        $this->assertSame([7, 0], $this->wallet());

        // The seller turns the suggestions down by cancelling the batch: the output was made, so nothing returns.
        $this->postJson("/api/v1/projects/{$project}/cancel", ['version' => $this->version($project)], ['Idempotency-Key' => 'cancel-after-output'])->assertOk();
        $this->assertSame([7, 0], $this->wallet());
        $this->assertSame(['grant', 'reserve', 'capture'], CreditLedgerEntry::query()->orderBy('id')->pluck('type')->map->value->all());
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
        $this->assertSame([10, 5], $this->wallet());
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
        $this->runCallback($started(2), ['usage' => ['model_requests' => 999, 'input_tokens' => 1, 'output_tokens' => 1, 'provider' => 'openai', 'model' => 'fixture-1']])->assertOk();
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
        $job = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'حدود 3 و حداکثر 5 اعتبار'))->last();
        $this->assertNotNull($job);
        $this->assertStringContainsString('اعتبار کافی نیست', $job->text);
        $this->assertSame([], $job->buttons);

        app(WalletRepositoryInterface::class)->appendEntry(Wallet::query()->sole(), CreditLedgerType::Grant, 3, 'test-top-up');
        $this->botText('/done', 5008);
        $offer = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->buttons !== [] && str_starts_with($job->buttons[0][0]['callback_data'], 'quote:accept:'))->last();
        $this->assertNotNull($offer);
        $this->assertStringContainsString('فقط مصرف واقعی (حداقل 1 اعتبار) کسر می‌شود، حتی اگر پیشنهادها را نپذیری', $offer->text);
        $this->botPress($offer->buttons[0][0]['callback_data'], 5008, 'cb-one');
        $this->assertSame('تأیید و شروع استخراج (حداکثر 5 اعتبار)', $offer->buttons[0][0]['text']);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '5 اعتبار برای استخراج پروژه')
            && str_contains($job->text, 'مصرف واقعی (حداقل 1 اعتبار) کسر می‌شود و بقیه برمی‌گردد'));
        $this->assertSame([5, 5], $this->wallet());
        // The same press again replays, and holds nothing more.
        $this->botPress($offer->buttons[0][0]['callback_data'], 5008, 'cb-one');
        $this->assertSame([5, 5], $this->wallet());
        $this->assertSame(1, AiWorkflowRun::query()->count());

        $this->botText('/balance', 5008);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, '5 اعتبار، که 5 اعتبار آن'));
    }

    public function test_the_ledger_and_balance_tie_each_charge_to_its_run_project_and_output(): void
    {
        $user = $this->seller(5020);
        $this->completeOnboarding($user);
        $project = $this->batch(2, 1);
        $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);
        $run = AiWorkflowRun::query()->sole();
        $this->runCallback($run)->assertOk();
        // A failed run releases its hold and is no charge.
        $failed = $this->batch(1, 3);
        $this->accept($failed, $this->quote($failed)->json('data.id'))->assertStatus(202);
        $this->runCallback(AiWorkflowRun::query()->latest('id')->firstOrFail(), ['status' => 'failed', 'error_code' => 'MODEL_BLOCKED', 'candidates' => []])->assertOk();

        $this->getJson('/api/v1/wallet/ledger')->assertOk()
            ->assertJsonPath('data.balance', 7)
            ->assertJsonPath('data.entries.0.type', 'release')
            ->assertJsonPath('data.entries.0.project_id', $failed)
            ->assertJsonPath('data.entries.2.type', 'capture')
            ->assertJsonPath('data.entries.2.amount', -3)
            ->assertJsonPath('data.entries.2.reason', 'product_extraction_succeeded')
            ->assertJsonPath('data.entries.2.output_class', 'file_to_text')
            ->assertJsonPath('data.entries.2.run_id', $run->public_id)
            ->assertJsonPath('data.entries.2.project_id', $project)
            ->assertJsonPath('data.entries.3.type', 'reserve')
            ->assertJsonPath('data.entries.3.run_id', $run->public_id)
            ->assertJsonPath('data.entries.4.type', 'grant')
            ->assertJsonPath('data.entries.4.output_class', null)
            ->assertJsonPath('data.entries.4.run_id', null)
            ->assertJsonPath('data.entries.4.project_id', null);

        $this->botText('/balance', 5020);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->text
            === "موجودی اعتبار تو: 7 اعتبار.\n\nآخرین کسرها:\n• 3 اعتبار: استخراج محصول از 2 عکس، پروژه «Batch»");
    }

    public function test_a_ledger_entry_never_shows_a_run_of_another_workspace(): void
    {
        $this->seller(5021);
        $project = $this->batch(1, 1);
        $this->accept($project, $this->quote($project)->json('data.id'))->assertStatus(202);
        $foreign = AiWorkflowRun::query()->sole();

        $this->seller(5022);
        $wallets = app(WalletRepositoryInterface::class);
        DB::transaction(fn () => $wallets->record(
            $wallets->lock(Wallet::query()->latest('id')->firstOrFail()->id), CreditLedgerType::Adjustment, 0, 0,
            'test-forged-link', CreditLedgerEntry::RELATED_RUN, $foreign->id, 'test_forged_link',
        ));

        $ledger = $this->getJson('/api/v1/wallet/ledger')->assertOk()
            ->assertJsonPath('data.entries.0.reason', 'test_forged_link')
            ->assertJsonPath('data.entries.0.run_id', null)
            ->assertJsonPath('data.entries.0.project_id', null)
            ->assertJsonPath('data.entries.0.output_class', null);
        $this->assertStringNotContainsString($foreign->public_id, (string) $ledger->getContent());
        $this->assertStringNotContainsString($project, (string) $ledger->getContent());
    }

    public function test_reconciliation_passes_a_sound_ledger_and_reports_each_broken_invariant(): void
    {
        $this->seller(5023);
        $done = $this->batch(1, 1);
        $this->accept($done, $this->quote($done)->json('data.id'))->assertStatus(202);
        $settledRun = AiWorkflowRun::query()->sole();
        $this->runCallback($settledRun)->assertOk();
        $open = $this->batch(1, 2);
        $this->accept($open, $this->quote($open)->json('data.id'))->assertStatus(202);
        $openRun = AiWorkflowRun::query()->latest('id')->firstOrFail();

        // A gift, a settled capture and an open hold reconcile.
        $this->artisan('credits:reconcile')->expectsOutput('{"wallets":1,"holds":2,"mismatches":0}')->assertSuccessful();
        $this->assertLedgerReconciles();

        $wallet = Wallet::query()->sole();
        $capture = CreditLedgerEntry::query()->where('type', 'capture')->sole();
        DB::table('credit_ledger')->where('id', $capture->id)->update(['amount' => -100]);
        DB::table('wallets')->where('id', $wallet->id)->update(['reserved_cached' => $wallet->reserved_cached + 1]);
        DB::table('ai_workflow_runs')->where('id', $settledRun->id)->update(['status' => 'dispatched']);
        DB::table('ai_workflow_runs')->where('id', $openRun->id)->update(['status' => 'failed']);
        DB::table('credit_ledger')->insert([
            'public_id' => (string) Str::uuid(), 'wallet_id' => $wallet->id, 'type' => 'release', 'amount' => 0, 'held_amount' => 0,
            'related_type' => CreditLedgerEntry::RELATED_RUN, 'related_id' => $openRun->id,
            'idempotency_key' => 'credit:settle:999999', 'reason' => 'test', 'created_at' => now(),
        ]);
        Log::spy();

        $mismatches = app(ReconcileCreditLedgerAction::class)->execute()['mismatches'];
        $checks = array_column($mismatches, 'check');
        sort($checks);
        $this->assertSame([
            'balance_cache_drift', 'capture_outside_hold', 'hold_unsettled', 'reserved_cache_drift', 'settled_open_run', 'settlement_without_hold',
        ], $checks);
        $this->assertContains(['check' => 'capture_outside_hold', 'wallet_id' => $wallet->id, 'entry_id' => CreditLedgerEntry::query()->findOrFail($settledRun->reservation_entry_id)->public_id], $mismatches);
        Log::shouldHaveReceived('error')->with('credit.reconcile_mismatch', ['check' => 'hold_unsettled', 'wallet_id' => $wallet->id, 'entry_id' => CreditLedgerEntry::query()->findOrFail($openRun->reservation_entry_id)->public_id]);
        $this->artisan('credits:reconcile')->assertFailed();
        // Reporting corrects nothing.
        $this->assertSame(-100, (int) DB::table('credit_ledger')->where('id', $capture->id)->value('amount'));
    }

    public function test_reconciliation_reports_settlements_that_disagree_with_their_hold_or_run(): void
    {
        $this->seller(5025);
        $failed = $this->batch(1, 1);
        $this->accept($failed, $this->quote($failed)->json('data.id'))->assertStatus(202);
        $failedRun = AiWorkflowRun::query()->sole();
        $this->runCallback($failedRun, ['status' => 'failed', 'error_code' => 'MODEL_BLOCKED', 'candidates' => []])->assertOk();
        $succeeded = $this->batch(1, 2);
        $this->accept($succeeded, $this->quote($succeeded)->json('data.id'))->assertStatus(202);
        $succeededRun = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->runCallback($succeededRun)->assertOk();
        $orphan = $this->batch(1, 3);
        $this->accept($orphan, $this->quote($orphan)->json('data.id'))->assertStatus(202);
        $orphanRun = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->seller(5026);
        $foreign = $this->batch(1, 4);
        $this->accept($foreign, $this->quote($foreign)->json('data.id'))->assertStatus(202);
        $foreignRun = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->assertSame([], app(ReconcileCreditLedgerAction::class)->execute()['mismatches']);

        $release = CreditLedgerEntry::query()->where('type', 'release')->sole();
        DB::table('credit_ledger')->where('id', $release->id)->update(['amount' => -1, 'held_amount' => $release->held_amount + 1]);
        $capture = CreditLedgerEntry::query()->where('type', 'capture')->sole();
        DB::table('credit_ledger')->where('id', $capture->id)->update(['related_id' => $failedRun->id]);
        DB::table('ai_workflow_runs')->where('id', $succeededRun->id)->update(['status' => 'failed']);
        DB::table('credit_ledger')->where('id', $orphanRun->reservation_entry_id)->update(['related_id' => 999_999]);
        DB::table('ai_workflow_runs')->where('id', $foreignRun->id)->update(['workspace_id' => $failedRun->workspace_id]);

        $found = array_map(fn (array $mismatch): string => $mismatch['check'].':'.($mismatch['entry_id'] ?? 'wallet'), app(ReconcileCreditLedgerAction::class)->execute()['mismatches']);
        $entry = fn (int $id): string => CreditLedgerEntry::query()->findOrFail($id)->public_id;
        $expected = [
            'balance_cache_drift:wallet',
            'reserved_cache_drift:wallet',
            'settlement_hold_mismatch:'.$entry($failedRun->reservation_entry_id),
            'release_moved_balance:'.$entry($failedRun->reservation_entry_id),
            'settlement_of_another_target:'.$entry($succeededRun->reservation_entry_id),
            'settlement_disagrees_with_run:'.$entry($succeededRun->reservation_entry_id),
            'hold_without_run:'.$entry($orphanRun->reservation_entry_id),
            'run_of_another_workspace:'.$entry($foreignRun->reservation_entry_id),
        ];
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found);
    }

    public function test_retention_drops_quotes_never_accepted_and_keeps_the_accepted_one(): void
    {
        $user = $this->seller(5024);
        $this->completeOnboarding($user);
        $project = $this->batch(1, 1);
        $abandoned = $this->quote($project)->json('data.id');
        $accepted = $this->quote($project)->json('data.id');
        $this->accept($project, $accepted)->assertStatus(202);
        $this->assertNotSame($abandoned, $accepted);

        // A limit set below a quote's life never reaches a quote still in it.
        config(['karmandeman.retention.credit_quote_hours' => 0]);
        $this->artisan('privacy:prune-retention')->assertSuccessful();
        $this->assertSame(2, CreditQuote::query()->count());
        config(['karmandeman.retention.credit_quote_hours' => 168]);

        $this->travel(167)->hours();
        $this->artisan('privacy:prune-retention')->assertSuccessful();
        $this->assertSame(2, CreditQuote::query()->count());

        $this->travel(2)->hours();
        $this->artisan('privacy:prune-retention')->assertSuccessful();
        $this->assertSame([$accepted], CreditQuote::query()->pluck('public_id')->all());
        $this->assertSame(CreditQuote::query()->sole()->id, AiWorkflowRun::query()->sole()->quote_id);

        // The button of a deleted quote is answered and starts nothing.
        $this->botPress("quote:accept:{$abandoned}", 5024, 'cb-pruned');
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->text === 'پیش‌فاکتور یافت نشد.');
        $this->assertSame(1, AiWorkflowRun::query()->count());
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
