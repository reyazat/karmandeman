<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Privacy\RequestAccountDeletionAction;
use App\Actions\Product\ApproveProductRevisionAction;
use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Step 3 slice: manual draft -> immutable revision -> Gate A -> JSON record (ADR-016). */
class ProductApiTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private const SECRET = 'test-webhook-secret';

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123:test-token',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function seller(int $telegramId, bool $consent = true): User
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
        if ($consent) {
            $this->grantPilotConsent($user);
        }

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function draft(string $status = 'confirmed'): array
    {
        return [
            'name' => ['value' => 'کیف مدل آوا', 'status' => $status],
            'sku' => ['value' => 'ARY-1007', 'status' => 'confirmed'],
            'pricing' => ['regular_price' => 1_250_000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
            'attributes' => [
                ['name' => 'رنگ', 'values' => ['کرم'], 'status' => 'confirmed'],
                ['name' => 'جنس', 'values' => null, 'status' => 'unknown'],
            ],
            'category_path' => ['value' => ['کیف زنانه'], 'status' => 'confirmed'],
        ];
    }

    private function create(string $project, array $draft, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/products", $draft, ['Idempotency-Key' => $key ?? 'product-'.++$this->keys]);
    }

    private function approve(string $product, int $revision, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/products/{$product}/approve", ['revision' => $revision], ['Idempotency-Key' => $key ?? 'approve-'.++$this->keys]);
    }

    public function test_manual_draft_is_stored_as_rial_with_server_side_provenance(): void
    {
        Sanctum::actingAs($this->seller(700));
        $draft = $this->draft();
        $draft['evidence'] = [['field' => 'name', 'source' => 'asset_forged']];
        $response = $this->create($this->project(), $draft)->assertCreated()
            ->assertJsonPath('data.schema_version', '1.0')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.pricing.regular_price_irr', 12_500_000)
            ->assertJsonPath('data.pricing.minor_currency', 'IRR')
            ->assertJsonPath('data.pricing.display_unit', 'toman')
            ->assertJsonPath('data.pricing.source', 'manual_override')
            ->assertJsonPath('data.attributes.1.values', null)
            ->assertJsonPath('data.attributes.1.status', 'unknown')
            ->assertJsonPath('data.description_fa', ['value' => null, 'status' => 'unknown'])
            ->assertJsonPath('data.approved_at', null);

        $this->assertSame(['manual_override'], array_values(array_unique(array_column($response->json('data.evidence'), 'source'))));
        $this->assertSame(
            ['name', 'sku', 'pricing.regular_price_irr', 'attributes.0', 'category_path'],
            array_column($response->json('data.evidence'), 'field'),
        );
        $this->getJson('/api/v1/products/'.$response->json('data.product_id'))->assertOk()
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.current.name.value', 'کیف مدل آوا');
    }

    public function test_unknown_price_stays_null_and_rial_input_is_not_multiplied(): void
    {
        Sanctum::actingAs($this->seller(701));
        $project = $this->project();
        $this->create($project, ['name' => ['value' => 'بدون قیمت', 'status' => 'confirmed']])->assertCreated()
            ->assertJsonPath('data.pricing.regular_price_irr', null)
            ->assertJsonPath('data.pricing.status', 'unknown')
            ->assertJsonPath('data.pricing.source', null);
        $this->create($project, ['pricing' => ['regular_price' => 500, 'input_unit' => 'IRR', 'status' => 'confirmed']])->assertCreated()
            ->assertJsonPath('data.pricing.regular_price_irr', 500)
            ->assertJsonPath('data.pricing.display_unit', 'rial');
    }

    public function test_invalid_drafts_are_refused_before_storage(): void
    {
        Sanctum::actingAs($this->seller(702));
        $project = $this->project();
        $invalid = [
            'negative price' => ['pricing' => ['regular_price' => -1, 'input_unit' => 'IRT', 'status' => 'confirmed']],
            'zero price' => ['pricing' => ['regular_price' => 0, 'input_unit' => 'IRT', 'status' => 'confirmed']],
            'price without unit' => ['pricing' => ['regular_price' => 1000, 'status' => 'confirmed']],
            'unknown unit' => ['pricing' => ['regular_price' => 1000, 'input_unit' => 'USD', 'status' => 'confirmed']],
            'unknown with value' => ['name' => ['value' => 'X', 'status' => 'unknown']],
            'confirmed without value' => ['sku' => ['value' => null, 'status' => 'confirmed']],
            'client provenance' => ['name' => ['value' => 'X', 'status' => 'confirmed', 'source' => 'asset_1']],
            'bad status' => ['name' => ['value' => 'X', 'status' => 'approved']],
            'control char sku' => ['sku' => ['value' => "A\u{0000}B", 'status' => 'confirmed']],
            'expected revision on create' => ['expected_revision' => 1, 'name' => ['value' => 'X', 'status' => 'confirmed']],
        ];
        foreach ($invalid as $case => $draft) {
            $this->create($project, $draft)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->postJson("/api/v1/projects/{$project}/products", $this->draft())->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_revisions', 0);
    }

    public function test_retry_replays_and_reused_key_with_other_draft_conflicts(): void
    {
        Sanctum::actingAs($this->seller(703));
        $project = $this->project();
        $first = $this->create($project, $this->draft(), 'product-retry-1')->assertCreated();
        $this->create($project, $this->draft(), 'product-retry-1')->assertOk()
            ->assertJsonPath('data.product_id', $first->json('data.product_id'));
        $this->create($project, $this->draft('suggested'), 'product-retry-1')->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_revisions', 1);
    }

    public function test_gate_a_approves_only_the_current_confirmed_revision(): void
    {
        Sanctum::actingAs($this->seller(704));
        $product = $this->create($this->project(), $this->draft('suggested'))->assertCreated()->json('data.product_id');

        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_NOT_APPROVED');
        $this->approve($product, 1)->assertUnprocessable()
            ->assertJsonPath('error.code', 'PRODUCT_ATTRIBUTE_REQUIRES_CONFIRMATION')
            ->assertJsonPath('error.details.fields', ['name']);
        $this->approve($product, 2)->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_REVISION_CONFLICT');

        $revise = fn (int $expected, array $draft) => $this->patchJson("/api/v1/products/{$product}/draft",
            $draft + ['expected_revision' => $expected], ['Idempotency-Key' => 'revise-'.++$this->keys]);
        $revise(1, $this->draft())->assertOk()->assertJsonPath('data.revision', 2);
        $revise(1, $this->draft())->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_REVISION_CONFLICT');
        $this->approve($product, 1)->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_REVISION_CONFLICT');

        $approved = $this->approve($product, 2)->assertOk()->assertJsonPath('data.revision', 2)->json('data');
        $this->assertNotNull($approved['approved_at']);
        $this->approve($product, 2)->assertOk()->assertJsonPath('data.approved_at', $approved['approved_at']);
        $this->getJson("/api/v1/products/{$product}")->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_revision', 2);

        // A later correction voids nothing already exported and needs Gate A again.
        $changed = $this->draft();
        $changed['pricing']['regular_price'] = 1_400_000;
        $revise(2, $changed)->assertOk()->assertJsonPath('data.revision', 3);
        $this->getJson("/api/v1/products/{$product}")->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.approved_revision', 2)
            ->assertJsonPath('data.current.pricing.regular_price_irr', 14_000_000);
        $this->getJson("/api/v1/products/{$product}/approved")->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.pricing.regular_price_irr', 12_500_000)
            ->assertJsonPath('data.approved_at', $approved['approved_at']);
        $this->approve($product, 2)->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_REVISION_CONFLICT');
        $this->assertSame(1, DB::table('product_revisions')->whereNotNull('approved_at')->count());
    }

    public function test_products_are_tenant_isolated(): void
    {
        $owner = $this->seller(705);
        $other = $this->seller(706);
        Sanctum::actingAs($owner);
        $project = $this->project();
        $product = $this->create($project, $this->draft())->json('data.product_id');
        $this->approve($product, 1)->assertOk();

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/products/{$product}")->assertNotFound()->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');
        $this->getJson("/api/v1/products/{$product}/approved")->assertNotFound();
        $this->getJson("/api/v1/projects/{$project}/products")->assertNotFound();
        $this->create($project, $this->draft())->assertNotFound()->assertJsonPath('error.code', 'PROJECT_NOT_FOUND');
        $this->approve($product, 1)->assertNotFound();
        $this->patchJson("/api/v1/products/{$product}/draft", $this->draft() + ['expected_revision' => 1], ['Idempotency-Key' => 'cross-tenant-1'])
            ->assertNotFound();
        $this->getJson("/api/v1/projects/{$project}/products/summary")->assertNotFound()->assertJsonPath('error.code', 'PROJECT_NOT_FOUND');
        $this->postJson("/api/v1/products/{$product}/archive", [], ['Idempotency-Key' => 'cross-tenant-2'])->assertNotFound();
        $this->getJson('/api/v1/products/1')->assertNotFound();
        $this->assertDatabaseCount('product_revisions', 1);
        $this->assertSame('approved', DB::table('products')->value('status'));

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$project}/products")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product)
            ->assertJsonPath('data.0.project_id', $project)
            ->assertJsonPath('data.0.current.name.value', 'کیف مدل آوا');
        $owner->update(['status' => 'suspended']);
        $this->getJson("/api/v1/products/{$product}")->assertForbidden();
    }

    public function test_writes_need_consent_and_stop_at_deletion_t0(): void
    {
        $user = $this->seller(707);
        Sanctum::actingAs($user);
        $project = $this->project();
        $product = $this->create($project, $this->draft())->json('data.product_id');

        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($user);
        DB::table('workspace_consents')->where('workspace_id', $workspace->id)->delete();
        $this->create($project, $this->draft())->assertForbidden()->assertJsonPath('error.code', 'POLICY_CONSENT_REQUIRED');
        $this->approve($product, 1)->assertForbidden();
        $this->postJson("/api/v1/products/{$product}/archive", [], ['Idempotency-Key' => 'archive-no-consent'])->assertForbidden();

        $this->grantPilotConsent($user);
        app(RequestAccountDeletionAction::class)->execute($workspace, $user, 'telegram');
        Sanctum::actingAs($user->fresh());
        $this->approve($product, 1)->assertForbidden();
        // A request authorized before T0 still meets the workspace lock (FR-086).
        try {
            app(ApproveProductRevisionAction::class)->execute($workspace, $user, 'approve-after-t0', $product, 1);
            $this->fail('Approval after T0 was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('ACCOUNT_DELETION_PENDING', $e->getMessage());
        }
        $this->assertSame(0, DB::table('product_revisions')->whereNotNull('approved_at')->count());
    }

    public function test_payload_is_encrypted_at_rest_and_the_mac_is_not_exposed(): void
    {
        Sanctum::actingAs($this->seller(708));
        $response = $this->create($this->project(), $this->draft())->assertCreated();
        $row = DB::table('product_revisions')->sole();
        $this->assertStringStartsWith('kmv1:', $row->payload);
        $this->assertStringNotContainsString('ARY-1007', $row->payload);
        $this->assertStringNotContainsString('ARY-1007', (string) DB::table('api_idempotency_records')->value('result_attributes'));
        $this->assertSame(64, strlen($row->payload_mac));
        $this->assertStringNotContainsString($row->payload_mac, $response->getContent());
        $this->assertArrayNotHasKey('workspace_id', $response->json('data'));
    }

    private function archive(string $product, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/products/{$product}/archive", [], ['Idempotency-Key' => $key ?? 'archive-'.++$this->keys]);
    }

    private function revise(string $product, int $expected, array $draft): TestResponse
    {
        return $this->patchJson("/api/v1/products/{$product}/draft", $draft + ['expected_revision' => $expected], ['Idempotency-Key' => 'revise-'.++$this->keys]);
    }

    public function test_text_is_normalized_and_the_raw_input_is_kept(): void
    {
        Sanctum::actingAs($this->seller(709));
        $project = $this->project();
        $draft = [
            'name' => ['value' => "كيف   مدل\u{200C}\u{200C}آوا \u{200C} ٢", 'status' => 'confirmed'],
            'sku' => ['value' => 'ary-۱۰۰۷', 'status' => 'confirmed'],
            'description_fa' => ['value' => "خط اول\r\n\r\n\r\nخط دوم", 'status' => 'confirmed'],
            'attributes' => [['name' => 'رنگ', 'values' => ['كرم', 'مشکی'], 'status' => 'confirmed']],
            'category_path' => ['value' => ['کیف زنانه'], 'status' => 'confirmed'],
        ];
        $response = $this->create($project, $draft)->assertCreated()
            ->assertJsonPath('data.name.value', "کیف مدل\u{200C}آوا ۲")
            ->assertJsonPath('data.sku.value', 'ary-1007')
            ->assertJsonPath('data.description_fa.value', "خط اول\n\nخط دوم")
            ->assertJsonPath('data.attributes.0.values', ['کرم', 'مشکی'])
            ->assertJsonPath('data.category_path.value', ['کیف زنانه']);
        $this->assertSame([
            'name.value' => $draft['name']['value'],
            'sku.value' => 'ary-۱۰۰۷',
            'description_fa.value' => $draft['description_fa']['value'],
            'attributes.0.values.0' => 'كرم',
        ], $response->json('data.originals'));
        $plain = $this->create($project, ['name' => ['value' => 'ساده', 'status' => 'confirmed']])->assertCreated();
        $this->assertStringContainsString('"originals":{}', $plain->getContent());

        $this->create($project, ['name' => ['value' => "کیف\u{2028}چرم\u{0085}مشکی", 'status' => 'confirmed']])
            ->assertCreated()->assertJsonPath('data.name.value', 'کیف چرم مشکی');
        $this->create($project, ['category_path' => ['value' => ['كيف'], 'status' => 'confirmed']])
            ->assertCreated()->assertJsonPath('data.originals', ['category_path.value.0' => 'كيف']);

        // Text that is only ZWNJ or tatweel is no value at all.
        foreach (['name' => ['value' => "\u{200C}\u{0640}", 'status' => 'confirmed'], 'attributes' => [['name' => "\u{200C}", 'values' => null]]] as $field => $value) {
            $this->create($project, [$field => $value])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        // Invalid UTF-8 is a validation error, not a server error.
        $this->post("/api/v1/projects/{$project}/products", ['name' => ['value' => "ab\xFF", 'status' => 'confirmed']],
            ['Idempotency-Key' => 'product-bad-utf8', 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('products', 4);
    }

    public function test_sku_is_unique_per_workspace_and_the_approved_sku_stays_held(): void
    {
        $owner = $this->seller(710);
        $other = $this->seller(711);
        Sanctum::actingAs($owner);
        $project = $this->project();
        $first = $this->create($project, $this->draft())->assertCreated()->json('data.product_id');

        $this->create($project, ['sku' => ['value' => 'ary-۱۰۰۷', 'status' => 'confirmed']])->assertStatus(409)
            ->assertJsonPath('error.code', 'PRODUCT_SKU_TAKEN');
        $this->create($this->project(), ['sku' => ['value' => 'ARY-1007', 'status' => 'suggested']])->assertStatus(409);
        // Arabic yeh, its presentation form and Persian yeh are one SKU.
        $this->create($project, ['sku' => ['value' => "\u{064A}1", 'status' => 'confirmed']])->assertCreated();
        $this->create($project, ['sku' => ['value' => "\u{FEF1}1", 'status' => 'confirmed']])->assertStatus(409);
        $this->create($project, ['sku' => ['value' => "\u{06CC}1", 'status' => 'confirmed']])->assertStatus(409);
        $this->assertDatabaseCount('products', 2);
        // A revision keeping its own SKU is no conflict.
        $this->revise($first, 1, $this->draft())->assertOk();

        Sanctum::actingAs($other);
        $this->create($this->project(), $this->draft())->assertCreated();

        Sanctum::actingAs($owner);
        $this->approve($first, 2)->assertOk();
        $moved = $this->draft();
        $moved['sku']['value'] = 'ARY-2000';
        $this->revise($first, 2, $moved)->assertOk();
        // The approved revision 2 still holds ARY-1007 until revision 3 is approved.
        $this->create($project, $this->draft())->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_SKU_TAKEN');
        $this->create($project, $moved)->assertStatus(409);
        $this->approve($first, 3)->assertOk();
        $second = $this->create($project, $this->draft())->assertCreated()->json('data.product_id');
        $this->revise($second, 1, $moved)->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_SKU_TAKEN');

        // Rejecting a product releases its SKU.
        $this->archive($first)->assertOk();
        $this->revise($second, 1, $moved)->assertOk();

        // Second product holds ARY-2000, the yeh product its SKU, the other workspace its own ARY-1007.
        $this->assertSame(3, DB::table('product_skus')->count());
        foreach (DB::table('product_skus')->pluck('sku_mac') as $mac) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $mac);
        }
        $this->assertStringNotContainsString('ARY', (string) DB::table('product_revisions')->pluck('sku_mac')->implode(','));
    }

    public function test_reject_archives_the_product_but_keeps_its_revisions(): void
    {
        Sanctum::actingAs($this->seller(712));
        $product = $this->create($this->project(), $this->draft())->json('data.product_id');
        $approved = $this->approve($product, 1)->assertOk()->json('data');

        $this->archive($product, 'archive-key-1')->assertOk()
            ->assertJsonPath('data.id', $product)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.approved_revision', 1)
            ->assertJsonPath('data.current.revision', 1);
        $this->archive($product, 'archive-key-1')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->archive($product)->assertOk()->assertJsonPath('data.status', 'archived');

        $this->revise($product, 1, $this->draft())->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_ARCHIVED');
        $this->approve($product, 1)->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_ARCHIVED');
        $this->getJson("/api/v1/products/{$product}/approved")->assertOk()
            ->assertJsonPath('data.approved_at', $approved['approved_at']);
        $this->assertDatabaseCount('product_revisions', 1);
        $this->assertDatabaseCount('product_skus', 0);
        $this->postJson("/api/v1/products/{$product}/archive")->assertUnprocessable();
    }

    public function test_approved_record_is_verified_before_it_is_returned(): void
    {
        Sanctum::actingAs($this->seller(730));
        $project = $this->project();
        $product = $this->create($project, $this->draft())->assertCreated()->json('data.product_id');
        $other = $this->create($project, ['name' => ['value' => 'دیگر', 'status' => 'confirmed']])->assertCreated()->json('data.product_id');
        $this->approve($product, 1)->assertOk();
        $this->approve($other, 1)->assertOk();
        $this->getJson("/api/v1/products/{$product}/approved")->assertOk()->assertJsonPath('data.name.value', 'کیف مدل آوا');
        $productId = DB::table('products')->where('public_id', $product)->value('id');
        $otherId = DB::table('products')->where('public_id', $other)->value('id');
        $row = fn (int $id) => DB::table('product_revisions')->where('product_id', $id);
        $original = (array) $row($productId)->first(['payload', 'payload_mac', 'approval_mac', 'approved_at']);
        $foreign = (array) $row($otherId)->first(['payload', 'payload_mac', 'approval_mac']);
        $this->assertSame(64, strlen($original['approval_mac']));
        $this->assertStringNotContainsString($original['approval_mac'], $this->getJson("/api/v1/products/{$product}/approved")->getContent());
        Log::spy();

        // Content and content MAC moved together between two products of one workspace.
        $row($productId)->update(['payload' => $foreign['payload'], 'payload_mac' => $foreign['payload_mac']]);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500)->assertJsonPath('error.code', 'PRODUCT_INTEGRITY_FAILED');
        // Even the whole approved row, approval MAC included, belongs to another product.
        $row($productId)->update(['approval_mac' => $foreign['approval_mac']]);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500);
        $row($productId)->update($original);
        $this->getJson("/api/v1/products/{$product}/approved")->assertOk();

        // Swapped public ids would serve one product under the other's id.
        DB::table('products')->where('id', $productId)->update(['public_id' => '00000000-0000-4000-8000-000000000001']);
        DB::table('products')->where('id', $otherId)->update(['public_id' => $product]);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500);
        DB::table('products')->where('id', $otherId)->update(['public_id' => $other]);
        DB::table('products')->where('id', $productId)->update(['public_id' => $product]);

        // A pre-ADR-019 row without a Gate A MAC, and an approval time moved in the database.
        $row($productId)->update(['approval_mac' => null]);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500);
        $row($productId)->update(['approval_mac' => $original['approval_mac'], 'approved_at' => '2020-01-01 00:00:00']);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500);
        $row($productId)->update($original);

        // An approval stamp set in the database, not by Gate A.
        $revised = $this->patchJson("/api/v1/products/{$product}/draft", [...$this->draft(), 'expected_revision' => 1], ['Idempotency-Key' => 'revise-730'])
            ->assertOk()->json('data.revision');
        $row($productId)->where('revision', $revised)->update(['approved_at' => now()]);
        DB::table('products')->where('id', $productId)->update(['approved_revision' => $revised]);
        $this->getJson("/api/v1/products/{$product}/approved")->assertStatus(500)->assertJsonPath('error.code', 'PRODUCT_INTEGRITY_FAILED');

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => $message === 'product.integrity_failed'
            && $context['product'] === $product && ! str_contains(json_encode($context), 'کیف'))->times(6);
    }

    public function test_near_duplicates_are_only_flagged_never_removed(): void
    {
        $seller = $this->seller(731);
        Sanctum::actingAs($seller);
        $project = $this->project();
        $named = fn (string $name, ?string $sku = null) => ['name' => ['value' => $name, 'status' => 'confirmed']]
            + ($sku === null ? [] : ['sku' => ['value' => $sku, 'status' => 'confirmed']]);
        $review = fn (string $product) => $this->getJson("/api/v1/products/{$product}")->assertOk()->json('data.review');

        // Spacing, ZWNJ, Arabic letter forms, diacritics and punctuation do not hide a repeat.
        $first = $this->create($project, $named('کیف‌چرمی آوا'))->assertCreated()->json('data.product_id');
        $second = $this->create($project, $named('كيف چرمي - آوا'))->assertCreated()->json('data.product_id');
        $this->assertSame(['duplicate_suspected' => true, 'duplicates' => [$second]], array_intersect_key($review($first), ['duplicate_suspected' => 1, 'duplicates' => 1]));
        $this->assertSame([$first], $review($second)['duplicates']);
        // The repository too: the same name in another batch of the workspace.
        $elsewhere = $this->create($this->project(), $named('کیفِ چرمیِ آوا'))->assertCreated()->json('data.product_id');
        $this->assertSame([$second, $elsewhere], $review($first)['duplicates']);

        // Two different SKUs are two products; one without a SKU stays suspect.
        $skuA = $this->create($project, $named('کفش', 'SH-1'))->json('data.product_id');
        $skuB = $this->create($project, $named('کفش', 'SH-2'))->json('data.product_id');
        $this->assertFalse($review($skuA)['duplicate_suspected']);
        $noSku = $this->create($project, $named('کفش'))->json('data.product_id');
        $this->assertSame([$skuA, $skuB], $review($noSku)['duplicates']);
        $this->assertSame([$noSku], $review($skuA)['duplicates']);

        $summary = "/api/v1/projects/{$project}/products/summary";
        $this->getJson($summary)->assertJsonPath('data.duplicate_suspected', 5);
        $ids = fn () => array_column($this->getJson("/api/v1/projects/{$project}/products?filter=duplicates")->assertOk()->json('data'), 'id');
        $this->assertSame([$first, $second, $skuA, $skuB, $noSku], $ids());

        // A warning never blocks Gate A, and nothing is merged or archived.
        $this->approve($first, 1)->assertOk();
        $this->assertTrue($review($first)['duplicate_suspected']);
        $this->assertSame(0, DB::table('products')->where('status', 'archived')->count());

        // Adding a SKU tells the products apart; so does a new name.
        $this->revise($noSku, 1, $named('کفش', 'SH-3'))->assertOk();
        $this->assertFalse($review($noSku)['duplicate_suspected']);
        $this->assertFalse($review($skuA)['duplicate_suspected']);
        $this->assertFalse($review($skuB)['duplicate_suspected']);
        $late = $this->create($project, $named('کفش'))->json('data.product_id');
        $this->assertTrue($review($skuA)['duplicate_suspected']);
        $this->revise($late, 1, $named('کفش ورزشی'))->assertOk();
        $this->assertFalse($review($skuA)['duplicate_suspected']);
        // Rejecting the only SKU-less member clears its SKU'd partners.
        $this->revise($late, 2, $named('کفش'))->assertOk();
        $this->assertTrue($review($skuB)['duplicate_suspected']);
        $this->archive($late)->assertOk();
        $this->assertFalse($review($skuB)['duplicate_suspected']);
        $this->archive($second)->assertOk();
        $this->assertSame([$elsewhere], $review($first)['duplicates']);
        $this->assertFalse($review($second)['duplicate_suspected']);
        $this->archive($elsewhere)->assertOk();
        $this->assertFalse($review($first)['duplicate_suspected']);
        $this->getJson($summary)->assertJsonPath('data.duplicate_suspected', 0);

        // Another workspace with the same name is never compared, and the name is not stored in clear.
        Sanctum::actingAs($this->seller(732));
        $foreign = $this->create($this->project(), $named('کیف چرمی آوا'))->assertCreated()->json('data.product_id');
        $this->assertFalse($review($foreign)['duplicate_suspected']);
        $row = DB::table('products')->where('public_id', $foreign)->first();
        $this->assertSame(64, strlen($row->name_mac));
        $this->assertNotSame(DB::table('products')->where('public_id', $first)->value('name_mac'), $row->name_mac);
        $this->assertStringNotContainsString($row->name_mac, $this->getJson("/api/v1/products/{$foreign}")->getContent());
        $this->assertStringNotContainsString('name_mac', $this->getJson("/api/v1/products/{$foreign}")->getContent());
        $this->assertStringNotContainsString('کیف', json_encode(DB::table('products')->get(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('آوا', json_encode(DB::table('products')->get(), JSON_UNESCAPED_UNICODE));
    }

    public function test_review_queue_and_batch_summary_are_counted_by_the_server(): void
    {
        Sanctum::actingAs($this->seller(713));
        $project = $this->project();
        $named = fn (string $name, string $status = 'confirmed') => ['name' => ['value' => $name, 'status' => $status]];
        $priced = fn (string $name, string $status = 'confirmed') => $named($name, $status)
            + ['pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed']];

        $ready = $this->create($project, $priced('آماده'))->json('data.product_id');
        $unsure = $this->create($project, $priced('نامطمئن', 'suggested'))->json('data.product_id');
        $noPrice = $this->create($project, $named('بدون قیمت'))->json('data.product_id');
        $done = $this->create($project, $priced('تأییدشده'))->json('data.product_id');
        $this->approve($done, 1)->assertOk();
        $rejected = $this->create($project, $named('ردشده', 'suggested'))->json('data.product_id');
        $this->archive($rejected)->assertOk();
        $this->create($this->project(), $named('پروژه دیگر', 'suggested'))->assertCreated();

        $summary = "/api/v1/projects/{$project}/products/summary";
        $this->getJson($summary)->assertOk()->assertJsonPath('data', [
            'total' => 5, 'needs_review' => 3, 'approved' => 1, 'archived' => 1,
            'ready_for_approval' => 2, 'needs_confirmation' => 1, 'price_missing' => 1, 'image_missing' => 4,
            'duplicate_suspected' => 0,
        ]);

        $ids = fn (string $query) => array_column($this->getJson("/api/v1/projects/{$project}/products{$query}")->assertOk()->json('data'), 'id');
        $this->assertSame([$ready, $unsure, $noPrice], $ids('?filter=needs_review'));
        $this->assertSame([$ready, $unsure, $noPrice, $done], $ids('?filter=incomplete'));
        $this->assertCount(5, $ids(''));
        $this->getJson("/api/v1/projects/{$project}/products?filter=deleted")->assertUnprocessable();
        for ($i = 0; $i < 20; $i++) {
            $this->create($project, $named("صف {$i}"))->assertCreated();
        }
        $this->assertStringContainsString('filter=incomplete', (string) $this->getJson("/api/v1/projects/{$project}/products?filter=incomplete")->json('links.next'));
        $this->getJson("/api/v1/products/{$unsure}")->assertJsonPath('data.review', [
            'needs_confirmation' => true, 'price_missing' => false, 'image_missing' => true,
            'duplicate_suspected' => false, 'duplicates' => [],
        ]);

        // Flags follow the current revision.
        $this->revise($unsure, 1, $priced('نامطمئن'))->assertOk();
        $this->revise($noPrice, 1, $priced('بدون قیمت'))->assertOk();
        $this->getJson($summary)->assertJsonPath('data.needs_confirmation', 0)
            ->assertJsonPath('data.price_missing', 20)
            ->assertJsonPath('data.ready_for_approval', 23);
    }
}
