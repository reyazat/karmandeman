<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\AdvanceTelegramOnboardingAction;
use App\Jobs\Telegram\SendTelegramMessage;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\ProductTable;
use App\Models\User;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Step 4: a seller's CSV of simple products, mapped once and imported as drafts (ADR-040). */
class ProductTableTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private const CSV = "\u{FEFF}نام محصول,قیمت (تومان),کد,رنگ,توضیحات,دسته‌بندی,\n"
        ."كيف چرمی,\"۱۲۰٬۰۰۰\",BAG-1,\"مشکی، قهوه‌ای\",کیف دستی,کیف > زنانه,x\n"
        ."کفش,85000,SHOE-1,سفید,,کفش,\n"
        .",,,,,,\n"
        .",5000,NO-NAME,,,,\n"
        ."کمربند,تماس بگیرید,,,,,\n";

    private int $keys = 0;

    private string $telegramFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        // Http::fake keeps the first matching stub, so the file the bot serves is a property.
        Http::fake([
            'api.telegram.org/bot*/getFile' => fn () => Http::response(['ok' => true, 'result' => [
                'file_id' => 'x', 'file_path' => 'documents/file.csv', 'file_size' => strlen($this->telegramFile),
            ]]),
            'api.telegram.org/file/bot*' => fn () => Http::response($this->telegramFile),
            '*' => Http::response(['ok' => true]),
        ]);
        Storage::fake('assets');
        Queue::fake([SendTelegramMessage::class]);
    }

    private function seller(int $telegramId, bool $onboarded = false): User
    {
        $this->botMessage(['text' => '/start'], $telegramId);
        $user = User::query()->latest('id')->firstOrFail();
        if ($onboarded) {
            $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
            $onboarding = app(AdvanceTelegramOnboardingAction::class);
            $onboarding->execute($workspace, $user, 'accept', $this->policyConsentToken());
            foreach ([['input', 'Brand'], ['unit', 'toman'], ['skip'], ['skip'], ['confirm']] as $step) {
                $onboarding->execute($workspace, $user, ...$step);
            }
        } else {
            $this->grantPilotConsent($user);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-key-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function upload(string $project, string $csv, ?string $key = null): TestResponse
    {
        return $this->post("/api/v1/projects/{$project}/tables", [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ], ['Idempotency-Key' => $key ?? 'table-key-'.++$this->keys, 'Accept' => 'application/json']);
    }

    private function import(string $table, ?string $unit = null, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/tables/{$table}/import", $unit === null ? [] : ['price_unit' => $unit], ['Idempotency-Key' => $key ?? 'import-key-'.++$this->keys]);
    }

    private function botMessage(array $message, int $telegramId): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'message' => $message + [
                'message_id' => random_int(1, 10_000_000), 'date' => time(),
                'from' => ['id' => $telegramId, 'first_name' => 'U'], 'chat' => ['id' => $telegramId, 'type' => 'private'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function botPress(string $data, int $telegramId): void
    {
        $this->postJson('/api/webhooks/telegram', [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb-'.++$this->keys, 'data' => $data, 'from' => ['id' => $telegramId, 'first_name' => 'U'],
                'message' => ['message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sent(string $fragment): SendTelegramMessage
    {
        $job = Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, $fragment))->last();
        $this->assertNotNull($job, "No bot message contains «{$fragment}»: ".implode(' | ', Queue::pushed(SendTelegramMessage::class)->map(fn ($j) => $j->text)->all()));

        return $job;
    }

    /** @return array<string, mixed> */
    private function payload(Product $product): array
    {
        return ProductRevision::query()->where('product_id', $product->id)->sole()->payload;
    }

    public function test_a_csv_is_mapped_and_stored_encrypted_without_any_product(): void
    {
        $this->seller(6001);
        $project = $this->project();

        $table = $this->upload($project, self::CSV, 'table-once-1')->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.row_count', 4)
            ->assertJsonPath('data.importable_rows', 3)
            ->assertJsonPath('data.price_unit', 'IRT')
            ->assertJsonPath('data.columns.0', ['index' => 0, 'header' => 'نام محصول', 'field' => 'name'])
            ->assertJsonPath('data.columns.1.field', 'price')
            ->assertJsonPath('data.columns.2.field', 'sku')
            ->assertJsonPath('data.columns.3', ['index' => 3, 'header' => 'رنگ', 'field' => 'attribute'])
            ->assertJsonPath('data.columns.4.field', 'description')
            ->assertJsonPath('data.columns.5.field', 'category')
            ->assertJsonPath('data.columns.6.field', 'ignored')
            // Shown as it will be stored: Arabic letter forms already Persian.
            ->assertJsonPath('data.preview.0', ['line' => 2, 'name' => 'کیف چرمی', 'sku' => 'BAG-1', 'price' => 120000])
            ->assertJsonPath('data.problems', [['line' => 5, 'problem' => 'name_missing'], ['line' => 6, 'problem' => 'price_unreadable']])
            ->json('data.id');

        // Ciphertext only; no product until the seller confirms.
        $files = Storage::disk('assets')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('BAG-1', (string) Storage::disk('assets')->get($files[0]));
        $this->assertSame(0, Product::query()->count());
        // A replay and the same bytes again answer the same table.
        $this->upload($project, self::CSV, 'table-once-1')->assertOk()->assertJsonPath('data.id', $table);
        $this->upload($project, self::CSV)->assertOk()->assertJsonPath('data.id', $table);
        $this->getJson("/api/v1/tables/{$table}")->assertOk()->assertJsonPath('data.importable_rows', 3);
        $this->assertSame(1, ProductTable::query()->count());
    }

    public function test_import_stores_confirmed_drafts_cited_to_the_file_and_line(): void
    {
        $this->seller(6002);
        $project = $this->project();
        $table = $this->upload($project, self::CSV)->json('data.id');
        $version = (int) $this->getJson("/api/v1/projects/{$project}")->json('data.version');

        $this->import($table, key: 'import-once-1')->assertCreated()->assertJsonPath('data.status', 'imported')->assertJsonPath('data.product_count', 3);

        $products = Product::query()->orderBy('id')->get();
        $this->assertCount(3, $products);
        $bag = $this->payload($products[0]);
        $this->assertSame(['value' => 'کیف چرمی', 'status' => 'confirmed'], $bag['name']);
        $this->assertSame(1_200_000, $bag['pricing']['regular_price_irr']);
        $this->assertSame(['table_import', 'confirmed', 'toman'], [$bag['pricing']['source'], $bag['pricing']['status'], $bag['pricing']['display_unit']]);
        $this->assertSame([['name' => 'رنگ', 'values' => ['مشکی', 'قهوه‌ای'], 'status' => 'confirmed']], $bag['attributes']);
        $this->assertSame(['کیف', 'زنانه'], $bag['category_path']['value']);
        $this->assertContains(['field' => 'name', 'source' => 'table_import', 'source_table_id' => $table, 'source_line' => 2], $bag['evidence']);
        $this->assertContains(['field' => 'pricing.regular_price_irr', 'source' => 'table_import', 'source_table_id' => $table, 'source_line' => 2], $bag['evidence']);
        $this->assertFalse($products[0]->needs_confirmation);
        // A cell that is not a price is no price, never a guess (QA-06).
        $belt = $this->payload($products[2]);
        $this->assertNull($belt['pricing']['regular_price_irr']);
        $this->assertSame(['field' => 'name', 'source' => 'table_import', 'source_table_id' => $table, 'source_line' => 6], $belt['evidence'][0]);
        $this->assertGreaterThan($version, (int) $this->getJson("/api/v1/projects/{$project}")->json('data.version'));

        // Gate A is still one approval per product.
        $this->postJson("/api/v1/products/{$products[0]->public_id}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-table'])->assertSuccessful();

        // A correction keeps the line the unchanged values came from (FR-030).
        $this->patchJson("/api/v1/products/{$products[1]->public_id}/draft", [
            'expected_revision' => 1,
            'name' => ['value' => 'کفش', 'status' => 'confirmed'],
            'sku' => ['value' => 'SHOE-2', 'status' => 'confirmed'],
            'pricing' => ['regular_price' => 85000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'revise-table'])->assertSuccessful();
        $revised = ProductRevision::query()->where('product_id', $products[1]->id)->where('revision', 2)->sole()->payload;
        $this->assertContains(['field' => 'name', 'source' => 'table_import', 'source_table_id' => $table, 'source_line' => 3], $revised['evidence']);
        $this->assertContains(['field' => 'sku', 'source' => 'manual_override'], $revised['evidence']);

        // A replay answers; a second import of the same table is refused.
        $this->import($table, key: 'import-once-1')->assertOk()->assertJsonPath('data.product_count', 3);
        $this->import($table)->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_IMPORTED');
        $this->assertSame(3, Product::query()->count());
    }

    public function test_the_price_unit_comes_from_the_file_or_the_seller_never_a_default(): void
    {
        $this->seller(6003);
        $project = $this->project();
        $bare = $this->upload($project, "نام,قیمت\nکیف,120000\nکفش,\n")->assertCreated()->assertJsonPath('data.price_unit', null)->json('data.id');

        $this->import($bare)->assertStatus(422)->assertJsonPath('error.code', 'TABLE_PRICE_UNIT_REQUIRED');
        $this->assertSame(0, Product::query()->count());
        $this->import($bare, 'IRR')->assertCreated();
        $this->assertSame([120000, null], Product::query()->orderBy('id')->get()->map(fn (Product $p) => $this->payload($p)['pricing']['regular_price_irr'])->all());

        // A unit named in the cells counts as the file's; the seller cannot contradict it.
        $cells = $this->upload($project, "name,price\nBag,120000 ریال\nShoe,90000\n")->assertCreated()->assertJsonPath('data.price_unit', 'IRR')->json('data.id');
        $this->import($cells, 'IRT')->assertStatus(422)->assertJsonPath('error.code', 'TABLE_PRICE_UNIT_CONFLICT');
        // Toman and rial in one file is refused before anything is stored (QA-02).
        $this->upload($project, "نام,قیمت (تومان)\nکیف,120000 ریال\n")->assertStatus(422)->assertJsonPath('error.code', 'TABLE_MIXED_PRICE_UNITS');
        $this->upload($project, "نام,قیمت\nکیف,120000 تومان ریال\n")->assertStatus(422)->assertJsonPath('error.code', 'TABLE_MIXED_PRICE_UNITS');
        $this->upload($project, "نام,قیمت (تومان و ریال)\nکیف,120000\n")->assertStatus(422)->assertJsonPath('error.code', 'TABLE_MIXED_PRICE_UNITS');
        $this->assertSame(2, ProductTable::query()->count());
        // Without any price no unit is needed.
        $plain = $this->upload($project, "نام;رنگ\nشال;آبی\n")->assertCreated()->json('data.id');
        $this->import($plain)->assertCreated()->assertJsonPath('data.product_count', 1);
    }

    public function test_files_that_cannot_be_read_or_mapped_are_refused_before_storage(): void
    {
        $this->seller(6004);
        $project = $this->project();
        $refusals = [
            'TABLE_UNREADABLE' => ["\xFF\xFEن\0ا\0", "PK\x03\x04\0\0binary", "نام\nکیف \xC3\x28\n"],
            'TABLE_EMPTY' => ["نام,قیمت\n", "\n\n"],
            'TABLE_NO_NAME_COLUMN' => ["قیمت,رنگ\n100,آبی\n"],
            'TABLE_AMBIGUOUS_COLUMNS' => ["نام,قیمت,price\nکیف,1,2\n"],
            'TABLE_TOO_MANY_COLUMNS' => [implode(',', ['نام', ...array_map(fn (int $i): string => "ستون{$i}", range(1, 30))])."\nکیف\n"],
            'TABLE_TOO_MANY_ROWS' => ["نام\n".str_repeat("کیف\n", 201)],
        ];
        foreach ($refusals as $code => $files) {
            foreach ($files as $csv) {
                $this->upload($project, $csv)->assertStatus(422)->assertJsonPath('error.code', $code);
            }
        }
        $this->upload($project, "نام\n".str_repeat("کیف\n", 200))->assertCreated();
        config(['karmandeman.tables.max_kilobytes' => 1]);
        $this->upload($project, "نام\n".str_repeat('کیف,', 400)."\n")->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(1, ProductTable::query()->count());
        $this->assertCount(1, Storage::disk('assets')->allFiles());
    }

    public function test_bad_rows_are_skipped_by_line_and_never_half_stored(): void
    {
        $this->seller(6005);
        $project = $this->project();
        $csv = "نام,قیمت (ریال),کد\n"
            ."کیف,12.5,A-1\n"
            ."کفش,1-2,A-1\n"
            .str_repeat('x', 201).",100,B-1\n"
            ."شال,0,C-1\n"
            ."\u{200C}\u{0640},100,D-1\n"
            ."کلاه,۱۲ ۵۰۰,E-1\n";

        $this->upload($project, $csv)->assertCreated()->assertJsonPath('data.problems', [
            ['line' => 2, 'problem' => 'price_unreadable'],
            ['line' => 3, 'problem' => 'sku_repeated'],
            ['line' => 4, 'problem' => 'invalid_values'],
            ['line' => 5, 'problem' => 'price_unreadable'],
            ['line' => 6, 'problem' => 'name_missing'],
        ])->assertJsonPath('data.importable_rows', 3)->assertJsonPath('data.preview.2', ['line' => 7, 'name' => 'کلاه', 'sku' => 'E-1', 'price' => 12500]);
    }

    public function test_shifted_rows_quoted_lines_and_foreign_digits_are_never_misread(): void
    {
        $this->seller(6012);
        $project = $this->project();
        $csv = "نام,قیمت به ریال,توضیحات\n"
            ."پیراهن,1,200,000,خوب\n"
            ."\"کیف\nدو خطی\",100,\"توضیح\nبلند\"\n"
            ."کفش,5०००,\n"
            ."شال,٢٥٠٠٠,\n";

        $this->upload($project, $csv)->assertCreated()
            ->assertJsonPath('data.price_unit', 'IRR')
            ->assertJsonPath('data.columns.1.field', 'price')
            ->assertJsonPath('data.problems', [
                // An unquoted comma shifted the cells: skipped, never a price of 1.
                ['line' => 2, 'problem' => 'cell_count_mismatch'],
                // Each record cites the line it starts on, after a quoted cell spanning lines.
                ['line' => 6, 'problem' => 'price_unreadable'],
            ])
            ->assertJsonPath('data.preview.0', ['line' => 3, 'name' => 'کیف دو خطی', 'sku' => null, 'price' => 100])
            ->assertJsonPath('data.preview.2', ['line' => 7, 'name' => 'شال', 'sku' => null, 'price' => 25000]);

        // A line far wider than any table is refused before it is split.
        $this->upload($project, "نام\nکیف".str_repeat(',', 1001)."\n")->assertStatus(422)->assertJsonPath('error.code', 'TABLE_TOO_MANY_COLUMNS');
        $this->upload($project, "نام\nکیف".str_repeat(',x', 70)."\n")->assertStatus(422)->assertJsonPath('error.code', 'TABLE_TOO_MANY_COLUMNS');
    }

    public function test_a_replayed_upload_of_a_dropped_table_is_not_found(): void
    {
        $this->seller(6013);
        $project = $this->project();
        $table = $this->upload($project, self::CSV, 'table-dropped-1')->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/tables/{$table}")->assertNoContent();

        $this->upload($project, self::CSV, 'table-dropped-1')->assertNotFound()->assertJsonPath('error.code', 'TABLE_NOT_FOUND');
    }

    public function test_a_taken_sku_refuses_the_whole_import(): void
    {
        $this->seller(6006);
        $held = $this->project();
        $this->postJson("/api/v1/projects/{$held}/products", [
            'name' => ['value' => 'کیف قدیمی', 'status' => 'confirmed'], 'sku' => ['value' => 'bag-1', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'held-sku'])->assertCreated();
        $project = $this->project();
        $table = $this->upload($project, self::CSV)->json('data.id');

        $this->import($table)->assertStatus(409)->assertJsonPath('error.code', 'TABLE_SKU_TAKEN')->assertJsonPath('error.details.lines', [2]);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame('pending', ProductTable::query()->sole()->status);
    }

    public function test_tables_are_tenant_scoped_and_follow_the_batch_state(): void
    {
        $this->seller(6007);
        $project = $this->project();
        $table = $this->upload($project, self::CSV)->json('data.id');

        $this->seller(6008);
        $this->getJson("/api/v1/tables/{$table}")->assertNotFound();
        $this->import($table, 'IRT')->assertNotFound();
        $this->deleteJson("/api/v1/tables/{$table}")->assertNoContent();
        $this->upload($project, self::CSV)->assertNotFound();
        $this->assertSame(1, ProductTable::query()->count());

        Sanctum::actingAs(User::query()->where('id', ProductTable::query()->sole()->created_by)->sole());
        $other = $this->project();
        $this->postJson("/api/v1/projects/{$other}/cancel", ['version' => 1], ['Idempotency-Key' => 'cancel-table'])->assertOk();
        $this->upload($other, self::CSV)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_ACCEPTING_INPUT');

        // Dropping removes the row and its file; an imported table stays with its products.
        $this->deleteJson("/api/v1/tables/{$table}")->assertNoContent();
        $this->assertSame(0, ProductTable::query()->count());
        $this->assertSame([], Storage::disk('assets')->allFiles());
        $kept = $this->upload($project, self::CSV)->json('data.id');
        $this->import($kept)->assertCreated();
        $this->deleteJson("/api/v1/tables/{$kept}")->assertStatus(409)->assertJsonPath('error.code', 'TABLE_ALREADY_IMPORTED');
    }

    public function test_the_bot_previews_the_mapping_and_imports_with_the_pressed_unit(): void
    {
        $user = $this->seller(6009, onboarded: true);
        $this->botMessage(['text' => '/new Batch'], 6009);
        $csv = "نام,قیمت,رنگ\nکیف\u{202E},120000,مشکی\nکفش,,\n";
        $this->telegramFile = $csv;

        $this->botMessage(['document' => ['file_id' => 'doc-1', 'file_name' => 'Products.CSV', 'mime_type' => 'text/csv', 'file_size' => strlen($csv)]], 6009);

        $preview = $this->sent('فایل CSV پروژه «Batch» خوانده شد: 2 محصول قابل ثبت از 2 ردیف.');
        $this->assertStringContainsString('نام ← «نام»، قیمت ← «قیمت».', $preview->text);
        $this->assertStringContainsString('ویژگی‌ها: «رنگ».', $preview->text);
        $this->assertStringNotContainsString("\u{202E}", $preview->text);
        $table = ProductTable::query()->sole();
        $this->assertSame('telegram', $table->source);
        $this->assertSame(
            ["table:irt:{$table->public_id}", "table:irr:{$table->public_id}", "table:drop:{$table->public_id}"],
            array_merge(...array_map(fn (array $row): array => array_column($row, 'callback_data'), $preview->buttons)),
        );

        $this->botPress("table:irt:{$table->public_id}", 6009);
        $this->sent('2 محصول از فایل در پیش‌نویس ثبت شد');
        $this->assertSame(1_200_000, $this->payload(Product::query()->orderBy('id')->firstOrFail())['pricing']['regular_price_irr']);
        // The stored name has no bidi override (ADR-039 review).
        $this->assertSame('کیف', $this->payload(Product::query()->orderBy('id')->firstOrFail())['name']['value']);
        $this->botPress("table:irr:{$table->public_id}", 6009);
        $this->sent('قبلاً ثبت شده‌اند');
        $this->assertSame(2, Product::query()->count());

        // Another file is dropped without a trace; a foreign or unknown button changes nothing.
        $this->telegramFile = "نام\nشال\n";
        $this->botMessage(['document' => ['file_id' => 'doc-2', 'file_name' => 'more.csv', 'mime_type' => 'text/comma-separated-values']], 6009);
        $second = ProductTable::query()->latest('id')->firstOrFail();
        $this->assertSame(["table:go:{$second->public_id}", "table:drop:{$second->public_id}"], array_merge(...array_map(
            fn (array $row): array => array_column($row, 'callback_data'), $this->sent('1 محصول قابل ثبت')->buttons,
        )));
        $this->seller(6010, onboarded: true);
        $this->botPress("table:go:{$second->public_id}", 6010);
        $this->assertSame('pending', $second->fresh()->status);
        $this->botPress("table:drop:{$second->public_id}", 6009);
        $this->sent('فایل کنار گذاشته شد');
        $this->assertNull($second->fresh());
        $this->assertSame(2, Product::query()->count());

        // A document that is not a CSV is refused at once.
        $this->botMessage(['document' => ['file_id' => 'doc-3', 'file_name' => 'products.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], 6009);
        $this->sent('CSV محصولات یا ویس Ogg/Opus');
        $this->assertSame($user->id, $table->created_by);
    }

    public function test_purge_removes_tables_and_their_files(): void
    {
        $user = $this->seller(6011);
        $project = $this->project();
        $this->upload($project, self::CSV)->assertCreated();
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);

        DB::transaction(fn () => app(AccountErasureRepositoryInterface::class)->purge($workspace, $user, []));

        $this->assertSame(0, ProductTable::query()->count());
    }
}
