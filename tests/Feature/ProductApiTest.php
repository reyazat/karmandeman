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
        $this->getJson('/api/v1/products/1')->assertNotFound();
        $this->assertDatabaseCount('product_revisions', 1);

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
}
