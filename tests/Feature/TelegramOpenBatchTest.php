<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Project\FailAIWorkflowRunAction;
use App\Jobs\AI\DispatchProductExtraction;
use App\Jobs\Telegram\AnswerTelegramCallback;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\AiWorkflowRun;
use App\Models\Asset;
use App\Models\CreditQuote;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\TelegramMediaIntake;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\DrivesExtractedBatches;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/**
 * A batch is open until approval; an approved one is corrected from its cards
 * and reopens by itself (ADR-041).
 */
class TelegramOpenBatchTest extends TestCase
{
    use DrivesExtractedBatches;

    private const BOT_ID = 123;

    private int $photoSeed = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy(aiProviders: ['google']);
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => self::BOT_ID.':test-token',
            'services.n8n.internal_url' => 'http://n8n.test',
            'services.n8n.workflow_auth_token' => 'test-workflow-token',
            'services.n8n.callback_secret' => self::CALLBACK_SECRET,
            'karmandeman.gift_credits' => 100,
        ]);
        // Http::fake keeps the first matching stub: each download serves the next fixture photo.
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'x', 'file_path' => 'photos/file.jpg', 'file_size' => 500]]),
            'api.telegram.org/file/bot*' => fn () => Http::response(ImageFixture::make(++$this->photoSeed)),
            '*' => Http::response(['ok' => true]),
        ]);
        Storage::fake('assets');
        Queue::fake([DispatchProductExtraction::class, SendTelegramMessage::class, AnswerTelegramCallback::class]);
    }

    /** @param  array<string, mixed>|null  $replyTo */
    private function photo(int $telegramId, ?array $replyTo = null): void
    {
        $this->update(['message' => [
            'message_id' => ++$this->updates, 'date' => time(),
            'photo' => [['file_id' => 'p-'.$this->updates, 'file_unique_id' => 'u', 'width' => 40, 'height' => 30, 'file_size' => 500]],
            'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
        ] + ($replyTo === null ? [] : ['reply_to_message' => $replyTo])]);
    }

    /** The bot message containing this text, as Telegram would quote it in a reply. */
    private function card(string $fragment, int $telegramId): array
    {
        $job = $this->sent($fragment);

        return [
            'message_id' => 900, 'date' => time(), 'text' => $job->text,
            'from' => ['id' => self::BOT_ID, 'is_bot' => true, 'first_name' => 'Karmandeman'],
            'chat' => ['id' => $telegramId, 'type' => 'private'],
            'reply_markup' => ['inline_keyboard' => $job->buttons],
        ];
    }

    /** A seller with one approved batch holding «کیف»; returns its card. */
    private function approvedBag(int $telegramId): array
    {
        $this->seller($telegramId);
        $this->text('/new Batch', $telegramId);
        $this->text("/product کیف\n۱۲۰٬۰۰۰ تومان", $telegramId);
        $product = Product::query()->latest('id')->firstOrFail();
        $card = $this->card('«کیف»', $telegramId);
        $this->press("product:approve:{$product->public_id}:1", $telegramId);
        $this->text('/done', $telegramId);
        $project = Project::query()->latest('id')->firstOrFail();
        $this->press("project:approve:{$project->public_id}:{$project->version}", $telegramId);
        $this->assertSame('product_approved', $project->fresh()->state->value);

        return $card;
    }

    public function test_a_photo_in_reply_to_a_card_reopens_the_approved_batch_and_joins_the_product(): void
    {
        $card = $this->approvedBag(7001);
        $product = Product::query()->sole();
        $project = Project::query()->sole();

        $this->photo(7001, $card);

        $this->assertSame('review_required', $project->fresh()->state->value);
        $this->assertSame(2, $product->fresh()->current_revision);
        $asset = Asset::query()->sole();
        $this->assertSame($project->id, $asset->project_id);
        $this->assertSame($product->id, $asset->claimed_by_product_id);
        $payload = ProductRevision::query()->where('product_id', $product->id)->where('revision', 2)->sole()->payload;
        $this->assertSame([$asset->public_id], $payload['image_asset_ids']);
        $this->assertSame('کیف', $payload['name']['value']);
        $reply = $this->sent('عکس به محصول');
        $this->assertStringContainsString('برای اصلاح دوباره باز شد', $reply->text);
        $this->assertSame(["product:approve:{$product->public_id}:2", "product:reject:{$product->public_id}:2"], $this->buttons($reply));
        // No new batch was opened for it, and the old snapshot is no longer the output.
        $this->assertSame(1, Project::query()->count());
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertStatus(409);
        $this->assertSame(0, TelegramMediaIntake::query()->count());
    }

    public function test_a_correction_makes_the_reopened_batch_current_so_done_finishes_it(): void
    {
        $card = $this->approvedBag(7010);
        $approved = Project::query()->sole();
        // Input after approval opened a new current batch.
        $this->text('/product شال', 7010);
        $other = Project::query()->latest('id')->firstOrFail();
        $this->assertNotSame($approved->id, $other->id);
        $workspace = $approved->workspace;
        $projects = app(ProjectRepositoryInterface::class);
        $this->assertSame($other->id, $projects->currentForBot($workspace)?->id);

        $this->photo(7010, $card);

        $this->assertSame('review_required', $approved->fresh()->state->value);
        $this->assertStringContainsString('برای اصلاح دوباره باز شد و پروژه جاری ربات است', $this->sent('عکس به محصول')->text);
        $this->assertSame($approved->id, $projects->currentForBot($workspace)?->id);
        $this->text('/done', 7010);
        Queue::assertNotPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, "«{$other->name}»") && str_contains($job->text, 'بسته'));
        $this->assertSame('collecting_input', $other->fresh()->state->value);
    }

    public function test_a_stale_foreign_or_full_card_stores_nothing_and_reopens_nothing(): void
    {
        $card = $this->approvedBag(7002);
        $product = Product::query()->sole();
        $project = Project::query()->sole();

        // Another seller's card: the product is not theirs.
        $this->seller(7003);
        $this->text('/new Other', 7003);
        $this->photo(7003, $card);
        $this->sent('محصول این کارت یافت نشد');

        // An outdated card: the product changed after it.
        $this->update(['message' => [
            'message_id' => ++$this->updates, 'date' => time(), 'text' => "کیف\n۱۵۰٬۰۰۰ تومان", 'reply_to_message' => $card,
            'from' => ['id' => 7002, 'first_name' => 'U'], 'chat' => ['id' => 7002, 'type' => 'private'],
        ]]);
        $this->assertSame(2, $product->fresh()->current_revision);
        $this->photo(7002, $card);
        $this->sent('پس از این کارت تغییر کرده است');
        $this->assertSame(2, $product->fresh()->current_revision);
        $this->assertSame(0, Asset::query()->count());

        // A product with ten photos takes no eleventh.
        $latest = ProductRevision::query()->where('product_id', $product->id)->where('revision', 2)->sole();
        $payload = $latest->payload;
        $payload['image_asset_ids'] = array_map(fn (int $i): string => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 10));
        $latest->forceFill(['payload' => $payload])->save();
        $this->photo(7002, $this->card('محصول اصلاح شد', 7002));
        $this->sent('10 عکس دارد');
        $this->assertSame(0, Asset::query()->count());
        $this->assertSame('review_required', $project->fresh()->state->value);
    }

    public function test_the_same_photo_twice_on_one_card_adds_it_once(): void
    {
        $this->seller(7004);
        $this->text('/new Batch', 7004);
        $this->text("/product کیف\n۱۰۰ تومان", 7004);
        $product = Product::query()->sole();
        $card = $this->card('«کیف»', 7004);
        $this->photo(7004, $card);
        $this->assertSame(2, $product->fresh()->current_revision);
        $second = $this->card('عکس به محصول', 7004);

        // The same bytes again, in reply to the new card: nothing changes.
        $this->photoSeed--;
        $this->photo(7004, $second);
        $this->sent('قبلاً برای همین محصول ثبت شده بود');
        $this->assertSame(2, $product->fresh()->current_revision);
        $this->assertSame(1, Asset::query()->count());
        $this->assertSame('collecting_input', Project::query()->sole()->state->value);
    }

    public function test_done_in_review_quotes_only_the_new_photos_and_keeps_the_cards(): void
    {
        config(['karmandeman.ai.extraction_enabled' => true]);
        [$user, $first] = $this->extracted(7005, [$this->candidate('کیف')]);
        $project = Project::query()->sole();
        $this->assertSame('review_required', $project->state->value);

        // A photo the model never saw: the batch in review takes it.
        $this->photo(7005);
        $this->sent('عکس به پروژه «Batch» اضافه شد');
        $new = Asset::query()->latest('id')->firstOrFail()->public_id;

        $this->text('/done', 7005);
        $quote = CreditQuote::query()->latest('id')->firstOrFail();
        $this->assertSame([$new], $quote->input_asset_ids);
        $this->sent('استخراج محصولات از 1 عکس تازه');
        $this->sent('«کیف»');
        $this->press("quote:accept:{$quote->public_id}", 7005);
        $this->assertSame('extracting', $project->fresh()->state->value);
        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();
        $this->assertSame([$new], $run->input_asset_ids);
        $this->assertNotSame($first, $new);
        $this->assertSame(1, Product::query()->count());
    }

    public function test_input_during_extraction_waits_for_the_next_done_and_never_takes_the_runs_photos(): void
    {
        config(['karmandeman.ai.extraction_enabled' => true]);
        $user = $this->seller(7006);
        $this->text('/new Batch', 7006);
        $this->photo(7006);
        $this->text('/done', 7006);
        $this->press('quote:accept:'.CreditQuote::query()->sole()->public_id, 7006);
        $project = Project::query()->sole();
        $this->assertSame('extracting', $project->state->value);
        $reading = AiWorkflowRun::query()->sole()->input_asset_ids;

        // A photo and a /product while the run reads: both kept, the run's photo untouched.
        $this->photo(7006);
        $this->text("/product شال\n۵۰ تومان", 7006);
        $shawl = ProductRevision::query()->where('product_id', Product::query()->sole()->id)->sole()->payload['image_asset_ids'];
        $this->assertCount(1, $shawl);
        $this->assertSame([], array_values(array_intersect($shawl, $reading)));
        $this->assertNull(Asset::query()->where('public_id', $reading[0])->value('claimed_by_product_id'));
        $this->assertSame(['extracting', 2], [$project->fresh()->state->value, Asset::query()->count()]);

        // The API refuses to put the run's photo on a product meanwhile.
        $this->postJson("/api/v1/projects/{$project->public_id}/products", [
            'name' => ['value' => 'کیف', 'status' => 'confirmed'], 'image_asset_ids' => $reading,
        ], ['Idempotency-Key' => 'api-run-photo'])->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_IMAGES_BEING_EXTRACTED');
        $this->post("/api/v1/projects/{$project->public_id}/assets", ['file' => UploadedFile::fake()->createWithContent('x.jpg', ImageFixture::make(999))],
            ['Idempotency-Key' => 'api-during-run', 'Accept' => 'application/json'])->assertCreated();
        $this->assertSame($reading, AiWorkflowRun::query()->sole()->input_asset_ids);
        $this->assertSame($user->id, DB::table('assets')->latest('id')->value('created_by'));
    }

    public function test_a_csv_or_photo_after_approval_opens_a_new_batch_and_the_old_one_stays_approved(): void
    {
        $this->approvedBag(7007);
        $old = Project::query()->sole();

        $this->photo(7007);

        $this->sent('«Batch» تأیید شده بود؛ پروژه تازه');
        $this->assertSame('product_approved', $old->fresh()->state->value);
        $newest = Project::query()->latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $newest->id);
        $this->assertSame($newest->id, Asset::query()->sole()->project_id);
        $this->sent('عکس به پروژه «'.$newest->name.'» اضافه شد');
    }

    public function test_a_card_photo_never_takes_a_photo_another_product_holds_or_a_run_reads(): void
    {
        config(['karmandeman.ai.extraction_enabled' => true]);
        $this->seller(7008);
        $this->text('/new Batch', 7008);
        $this->photo(7008);
        $this->text("/product کیف\n۱۰۰ تومان", 7008);
        $this->text("/product کفش\n۲۰۰ تومان", 7008);
        [$bag, $shoe] = Product::query()->orderBy('id')->get()->all();
        $held = Asset::query()->sole();
        $this->assertSame($bag->id, $held->claimed_by_product_id);

        // The bag's photo again, in reply to the shoe's card.
        $this->photoSeed = 100;
        $this->photo(7008, $this->card('«کفش»', 7008));
        $this->sent('برای محصول دیگری ثبت شده یا در حال استخراج است');
        $this->assertSame($bag->id, $held->fresh()->claimed_by_product_id);
        $this->assertSame(1, $shoe->fresh()->current_revision);

        // A photo a run is reading stays with the run, from a card or through the API.
        $this->photoSeed = 200;
        $this->photo(7008);
        $this->text('/done', 7008);
        $this->press('quote:accept:'.CreditQuote::query()->latest('id')->firstOrFail()->public_id, 7008);
        $run = AiWorkflowRun::query()->sole();
        $this->photoSeed = 200;
        $this->photo(7008, $this->card('«کفش»', 7008));
        $this->sent('برای محصول دیگری ثبت شده یا در حال استخراج است');
        $this->patchJson("/api/v1/products/{$shoe->public_id}/draft", [
            'expected_revision' => 1, 'name' => ['value' => 'کفش', 'status' => 'confirmed'], 'image_asset_ids' => $run->input_asset_ids,
        ], ['Idempotency-Key' => 'patch-run-photo'])->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_IMAGES_BEING_EXTRACTED');
        $this->assertSame(1, $shoe->fresh()->current_revision);

        // Products stay rejectable while the run reads.
        $this->press("product:reject:{$shoe->public_id}:1", 7008);
        $this->assertSame('archived', $shoe->fresh()->status->value);
    }

    public function test_a_failed_run_started_from_review_leaves_the_batch_in_review(): void
    {
        config(['karmandeman.ai.extraction_enabled' => true]);
        $this->extracted(7009, [$this->candidate('کیف')]);
        $project = Project::query()->sole();
        $this->photo(7009);
        $this->text('/done', 7009);
        $quote = CreditQuote::query()->latest('id')->firstOrFail();

        // A Gate A press after the quote leaves its photos as they were: the quote still holds.
        $product = Product::query()->sole();
        $this->press("product:confirm:{$product->public_id}:1", 7009);
        $this->press("quote:accept:{$quote->public_id}", 7009);
        $this->assertSame('extracting', $project->fresh()->state->value);

        $run = AiWorkflowRun::query()->latest('id')->firstOrFail();
        app(FailAIWorkflowRunAction::class)->execute($run->id, 'MODEL_BLOCKED');
        $this->assertSame('review_required', $project->fresh()->state->value);
    }

    public function test_reject_reopens_an_approved_batch_but_never_one_past_approval_or_from_a_stale_card(): void
    {
        $card = $this->approvedBag(7020);
        $product = Product::query()->sole();
        $project = Project::query()->sole();

        // Past approval (quote or credit, step 6): nothing reopens.
        Project::query()->whereKey($project->id)->update(['state' => 'quoted']);
        $this->press("product:reject:{$product->public_id}:1", 7020);
        $this->sent('از مرحله تأیید گذشته است و محصولش رد نمی‌شود');
        $this->assertSame('approved', $product->fresh()->status->value);
        Project::query()->whereKey($project->id)->update(['state' => 'product_approved']);

        // A photo on the card makes revision 2; the batch is approved again with it.
        $this->photo(7020, $card);
        $this->press("product:approve:{$product->public_id}:2", 7020);
        $this->text('/done', 7020);
        $project->refresh();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 7020);
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $approvedVersion = $project->fresh()->version;

        // The old card's reject: refused, and the approved batch stays as it was.
        $this->press("product:reject:{$product->public_id}:1", 7020);
        $this->sent('پس از این پیام تغییر کرده است');
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $this->assertSame($approvedVersion, $project->fresh()->version);
        $this->assertSame('approved', $product->fresh()->status->value);

        // The current card's reject reopens the batch and rejects in one step (ADR-044).
        $this->press("product:reject:{$product->public_id}:2", 7020);
        $reply = $this->sent('رد شد');
        $this->assertStringContainsString('برای اصلاح دوباره باز شد', $reply->text);
        $this->assertSame('archived', $product->fresh()->status->value);
        $this->assertSame('review_required', $project->fresh()->state->value);
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertStatus(409);
        $this->assertSame(2, DB::table('project_snapshots')->where('project_id', $project->id)->count());
    }

    public function test_product_never_takes_a_photo_a_product_from_the_api_cites(): void
    {
        $this->seller(7021);
        $this->text('/new Batch', 7021);
        $this->photo(7021);
        $this->photo(7021);
        $project = Project::query()->sole();
        [$first, $second] = Asset::query()->orderBy('id')->pluck('public_id')->all();
        $this->postJson("/api/v1/projects/{$project->public_id}/products", [
            'name' => ['value' => 'کیف', 'status' => 'confirmed'], 'image_asset_ids' => [$first],
        ], ['Idempotency-Key' => 'api-product-photo'])->assertCreated();

        $this->text('/product شال', 7021);

        $shawl = Product::query()->latest('id')->firstOrFail();
        $this->assertSame([$second], $this->payload($shawl, 1)['image_asset_ids']);
        // The next /product finds no photo left.
        $this->text('/product کفش', 7021);
        $this->assertSame([], $this->payload(Product::query()->latest('id')->firstOrFail(), 1)['image_asset_ids'] ?? []);
    }

    public function test_a_batch_in_extraction_is_listed_and_can_be_selected_again(): void
    {
        $this->seller(7022);
        $this->text('/new Extracting', 7022);
        $this->photo(7022);
        $extracting = Project::query()->sole();
        Project::query()->whereKey($extracting->id)->update(['state' => 'extracting']);
        $this->text('/new Other', 7022);

        $this->text('/projects', 7022);
        $list = $this->sent('آخرین پروژه‌ها');
        $this->assertStringContainsString('Extracting — در حال استخراج — '.now()->format('Y-m-d'), $list->text);
        $this->assertStringContainsString('1 عکس، 0 فایل CSV، 0 محصول؛ مصرف 0 اعتبار', $list->text);
        $this->assertSame(["project:select:{$extracting->public_id}"], $this->buttons($list));

        $this->press("project:select:{$extracting->public_id}", 7022);
        $this->sent('«Extracting» انتخاب شد؛ عکس، فایل CSV و /product به این پروژه می‌روند. استخراج آن در جریان است');
        $this->photo(7022);
        $this->assertSame(2, Asset::query()->where('project_id', $extracting->id)->count());
    }

    public function test_the_project_list_shows_files_products_and_credit_used_in_both_channels(): void
    {
        config(['karmandeman.ai.extraction_enabled' => true]);
        [$owner] = $this->extracted(7023, [$this->candidate('کیف'), $this->candidate('شال')]);
        $project = Project::query()->sole();
        $used = -(int) DB::table('credit_ledger')->where('type', 'capture')->sum('amount');
        $this->assertGreaterThan(0, $used);
        // Another seller's batch is never counted in.
        $this->extracted(7024, [$this->candidate('کفش')]);
        Sanctum::actingAs($owner);

        $row = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))->firstWhere('id', $project->public_id);
        $this->assertSame(['photos' => 1, 'tables' => 0, 'products' => 2, 'credits_used' => $used], $row['activity']);

        $this->text('/projects', 7023);
        $this->assertStringContainsString("1 عکس، 0 فایل CSV، 2 محصول؛ مصرف {$used} اعتبار", $this->sent('آخرین پروژه‌ها')->text);
    }

    public function test_an_old_reject_card_of_a_product_rejected_before_approval_reopens_nothing(): void
    {
        $this->seller(7025);
        $this->text('/new Batch', 7025);
        $this->text("/product کیف\n۱۲۰٬۰۰۰ تومان", 7025);
        $this->text("/product شال\n۹۰٬۰۰۰ تومان", 7025);
        [$bag, $shawl] = Product::query()->orderBy('id')->get()->all();
        $this->press("product:reject:{$shawl->public_id}:1", 7025);
        $this->press("product:approve:{$bag->public_id}:1", 7025);
        $this->text('/done', 7025);
        $project = Project::query()->sole();
        $this->press("project:approve:{$project->public_id}:{$project->version}", 7025);
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $version = $project->fresh()->version;

        // Same revision as the card, but rejected already: the approved batch stays as it was.
        $this->press("product:reject:{$shawl->public_id}:1", 7025);
        $this->sent('این محصول قبلاً رد شده است');
        $this->assertSame('product_approved', $project->fresh()->state->value);
        $this->assertSame($version, $project->fresh()->version);
        $this->getJson("/api/v1/projects/{$project->public_id}/snapshot")->assertOk();
    }
}
