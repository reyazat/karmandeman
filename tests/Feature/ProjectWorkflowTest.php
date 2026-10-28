<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Batch lifecycle: finish-input -> approve-products -> verified snapshot (ADR-018). */
class ProjectWorkflowTest extends TestCase
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

        return $user;
    }

    private function project(): string
    {
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function product(string $project, string $name, bool $approve = true): string
    {
        $id = $this->postJson("/api/v1/projects/{$project}/products", [
            'name' => ['value' => $name, 'status' => 'confirmed'],
            'pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
        ], ['Idempotency-Key' => 'product-'.++$this->keys])->assertCreated()->json('data.product_id');
        if ($approve) {
            $this->postJson("/api/v1/products/{$id}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-'.++$this->keys])->assertOk();
        }

        return $id;
    }

    private function version(string $project): int
    {
        return (int) $this->getJson("/api/v1/projects/{$project}")->assertOk()->json('data.version');
    }

    private function finish(string $project, int $version, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $version], ['Idempotency-Key' => $key ?? 'finish-'.++$this->keys]);
    }

    private function approveAll(string $project, int $version, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/approve-products", ['version' => $version], ['Idempotency-Key' => $key ?? 'approve-all-'.++$this->keys]);
    }

    public function test_finish_input_needs_a_product_the_current_version_and_runs_once(): void
    {
        Sanctum::actingAs($this->seller(800));
        $project = $this->project();
        $this->finish($project, 1)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_HAS_NO_PRODUCTS');
        $rejected = $this->product($project, 'رد', approve: false);
        $this->postJson("/api/v1/products/{$rejected}/archive", [], ['Idempotency-Key' => 'archive-rejected'])->assertOk();
        $this->finish($project, 3)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_HAS_NO_PRODUCTS');

        // Each product change bumps the batch version: create 1->2, archive 2->3, create 3->4.
        $this->assertSame(3, $this->version($project));
        $this->product($project, 'کیف', approve: false);
        $this->finish($project, 3)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_VERSION_CONFLICT');
        $this->finish($project, 4, 'finish-once')->assertOk()
            ->assertJsonPath('data.state', 'review_required')
            ->assertJsonPath('data.version', 5);
        $this->finish($project, 4, 'finish-once')->assertOk()->assertJsonPath('data.version', 5);
        $this->finish($project, 5)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_STATE_CONFLICT');
        $this->postJson("/api/v1/projects/{$project}/finish-input", [], ['Idempotency-Key' => 'finish-no-version'])->assertUnprocessable();
        $this->getJson("/api/v1/projects/{$project}")->assertJsonPath('data.state', 'review_required');
    }

    public function test_batch_approval_needs_gate_a_for_every_product_and_freezes_a_snapshot(): void
    {
        Sanctum::actingAs($this->seller(801));
        $project = $this->project();
        $first = $this->product($project, 'کیف');
        $pending = $this->product($project, 'کفش', approve: false);
        $rejected = $this->product($project, 'رد', approve: false);
        $this->postJson("/api/v1/products/{$rejected}/archive", [], ['Idempotency-Key' => 'archive-rejected'])->assertOk();

        $this->approveAll($project, $this->version($project))->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_STATE_CONFLICT');
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_APPROVED');
        $this->finish($project, $this->version($project))->assertOk();
        $this->approveAll($project, $this->version($project))->assertUnprocessable()
            ->assertJsonPath('error.code', 'PROJECT_PRODUCTS_NOT_APPROVED')
            ->assertJsonPath('error.details.products', [$pending]);
        $this->assertDatabaseCount('project_snapshots', 0);

        $seen = $this->version($project);
        $this->postJson("/api/v1/products/{$pending}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-pending'])->assertOk();
        $this->approveAll($project, $seen)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_VERSION_CONFLICT');
        $version = $this->version($project);
        $snapshot = $this->approveAll($project, $version, 'approve-all-once')->assertCreated()
            ->assertJsonPath('data.project_id', $project)
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.product_count', 2)
            ->assertJsonMissingPath('data.products')
            ->json('data.snapshot_id');
        $this->approveAll($project, $version, 'approve-all-once')->assertOk()->assertJsonPath('data.snapshot_id', $snapshot);
        $this->approveAll($project, $version + 1)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_STATE_CONFLICT');
        $this->getJson("/api/v1/projects/{$project}")->assertJsonPath('data.state', 'product_approved')->assertJsonPath('data.version', $version + 1);
        $this->assertDatabaseCount('project_snapshots', 1);

        // Later edits stay in the Product Repository; the snapshot keeps what was approved (FR-043).
        $this->patchJson("/api/v1/products/{$first}/draft", [
            'name' => ['value' => 'کیف تازه', 'status' => 'confirmed'], 'expected_revision' => 1,
        ], ['Idempotency-Key' => 'revise-after-snapshot'])->assertOk();
        $this->postJson("/api/v1/projects/{$project}/products", ['name' => ['value' => 'دیر', 'status' => 'confirmed']], ['Idempotency-Key' => 'late-product'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NOT_ACCEPTING_PRODUCTS');

        $response = $this->getJson("/api/v1/projects/{$project}/snapshot")->assertOk()
            ->assertJsonPath('data.snapshot_id', $snapshot)
            ->assertJsonCount(2, 'data.products')
            ->assertJsonPath('data.products.0.product_id', $first)
            ->assertJsonPath('data.products.0.revision', 1)
            ->assertJsonPath('data.products.0.name.value', 'کیف')
            ->assertJsonPath('data.products.1.product_id', $pending)
            ->assertJsonPath('data.products.1.pricing.regular_price_irr', 10_000);
        $this->assertNotNull($response->json('data.products.0.approved_at'));
        $row = DB::table('project_snapshots')->sole();
        $this->assertStringNotContainsString($row->snapshot_mac, $response->getContent());
        $this->assertStringNotContainsString('کیف', $row->items);
        $this->assertArrayNotHasKey('workspace_id', $response->json('data'));
    }

    public function test_tampered_snapshot_or_revision_is_never_exported(): void
    {
        Sanctum::actingAs($this->seller(802));
        $project = $this->project();
        $first = $this->product($project, 'کیف');
        $second = $this->product($project, 'کفش');
        $this->finish($project, $this->version($project))->assertOk();
        $this->approveAll($project, $this->version($project))->assertCreated();
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertOk();
        $firstId = DB::table('products')->where('public_id', $first)->value('id');
        Log::spy();

        // A payload swapped between two revisions of one workspace still decrypts (ADR-016); its MAC does not match.
        $payloads = DB::table('product_revisions')->orderBy('id')->pluck('payload', 'id');
        [$a, $b] = $payloads->keys()->all();
        DB::table('product_revisions')->where('id', $a)->update(['payload' => $payloads[$b]]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(500)->assertJsonPath('error.code', 'SNAPSHOT_INTEGRITY_FAILED');
        DB::table('product_revisions')->where('id', $a)->update(['payload' => $payloads[$a]]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertOk();

        // Pointing the snapshot at another revision breaks its own MAC.
        $items = DB::table('project_snapshots')->value('items');
        DB::table('project_snapshots')->update(['items' => str_replace('"revision":1', '"revision":2', $items)]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(500);
        DB::table('project_snapshots')->update(['items' => $items]);

        // Swapped public ids would relabel the export; the signed item list refuses it.
        DB::table('products')->where('public_id', $first)->update(['public_id' => '00000000-0000-4000-8000-000000000001']);
        DB::table('products')->where('public_id', $second)->update(['public_id' => $first]);
        DB::table('products')->where('public_id', '00000000-0000-4000-8000-000000000001')->update(['public_id' => $second]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(500)->assertJsonPath('error.code', 'SNAPSHOT_INTEGRITY_FAILED');
        DB::table('products')->where('public_id', $first)->update(['public_id' => '00000000-0000-4000-8000-000000000001']);
        DB::table('products')->where('public_id', $second)->update(['public_id' => $first]);
        DB::table('products')->where('public_id', '00000000-0000-4000-8000-000000000001')->update(['public_id' => $second]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertOk();

        // Ciphertext from another column fails its AAD check: an integrity failure, not a generic error.
        DB::table('product_revisions')->where('id', $a)->update(['payload' => DB::table('projects')->value('name')]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(500)->assertJsonPath('error.code', 'SNAPSHOT_INTEGRITY_FAILED');
        DB::table('product_revisions')->where('id', $a)->update(['payload' => $payloads[$a]]);

        // An approval stamp removed after the fact.
        DB::table('product_revisions')->where('product_id', $firstId)->update(['approved_at' => null]);
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertStatus(500);

        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => $message === 'product.snapshot_integrity_failed'
            && ! str_contains(json_encode($context), 'کیف'))->times(5);
    }

    public function test_batch_lifecycle_is_tenant_isolated_and_needs_consent(): void
    {
        $owner = $this->seller(803);
        $other = $this->seller(804);
        Sanctum::actingAs($owner);
        $project = $this->project();
        $this->product($project, 'کیف');

        Sanctum::actingAs($other);
        $this->finish($project, 3)->assertNotFound()->assertJsonPath('error.code', 'PROJECT_NOT_FOUND');
        $this->approveAll($project, 1)->assertNotFound();
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertNotFound();

        Sanctum::actingAs($owner);
        $workspace = app(WorkspaceRepositoryInterface::class)->currentFor($owner);
        DB::table('workspace_consents')->where('workspace_id', $workspace->id)->delete();
        $this->finish($project, 3)->assertForbidden()->assertJsonPath('error.code', 'POLICY_CONSENT_REQUIRED');
        $this->grantPilotConsent($owner);
        $this->finish($project, 3)->assertOk();
        DB::table('workspace_consents')->where('workspace_id', $workspace->id)->delete();
        $this->approveAll($project, 4)->assertForbidden();
        $this->assertDatabaseCount('project_snapshots', 0);
    }

    public function test_a_product_change_after_the_user_looked_voids_the_batch_approval(): void
    {
        Sanctum::actingAs($this->seller(805));
        $project = $this->project();
        $this->product($project, 'الف');
        $dropped = $this->product($project, 'ب');
        $this->finish($project, $this->version($project))->assertOk();
        $seen = $this->version($project);

        // Another tab rejects B and adds nothing; the batch the user saw is gone.
        $this->postJson("/api/v1/products/{$dropped}/archive", [], ['Idempotency-Key' => 'other-tab-archive'])->assertOk();
        $this->approveAll($project, $seen)->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_VERSION_CONFLICT');
        $this->assertDatabaseCount('project_snapshots', 0);

        $this->approveAll($project, $this->version($project))->assertCreated()->assertJsonPath('data.product_count', 1);
    }
}
