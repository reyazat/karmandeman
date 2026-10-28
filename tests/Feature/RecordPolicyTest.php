<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Brand\EnsureBrandAction;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

/** Record Policies behind the repository filter, and the lock-guarded brand read (ADR-021). */
class RecordPolicyTest extends TestCase
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

    /**
     * @return array{string, string, string} project, draft product and approved product public ids
     */
    private function batch(): array
    {
        $project = $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-'.++$this->keys])
            ->assertCreated()->json('data.id');
        $products = [];
        foreach (['کیف', 'کفش'] as $name) {
            $products[] = $this->postJson("/api/v1/projects/{$project}/products", [
                'name' => ['value' => $name, 'status' => 'confirmed'],
                'pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
            ], ['Idempotency-Key' => 'product-'.++$this->keys])->assertCreated()->json('data.product_id');
        }
        $this->postJson("/api/v1/products/{$products[1]}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-'.++$this->keys])->assertOk();

        return [$project, $products[0], $products[1]];
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}> every record endpoint: method, path, body
     */
    private function endpoints(string $project, string $draft, string $approved): array
    {
        return [
            'project.show' => ['GET', "/api/v1/projects/{$project}", []],
            'products.index' => ['GET', "/api/v1/projects/{$project}/products", []],
            'products.summary' => ['GET', "/api/v1/projects/{$project}/products/summary", []],
            'snapshot' => ['GET', "/api/v1/projects/{$project}/snapshot", []],
            'product.show' => ['GET', "/api/v1/products/{$draft}", []],
            'product.approved' => ['GET', "/api/v1/products/{$approved}/approved", []],
            'wallet' => ['GET', '/api/v1/wallet/ledger', []],
            'products.store' => ['POST', "/api/v1/projects/{$project}/products", [
                'name' => ['value' => 'تازه', 'status' => 'confirmed'],
                'pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
            ]],
            'product.revise' => ['PATCH', "/api/v1/products/{$draft}/draft", [
                'expected_revision' => 1,
                'name' => ['value' => 'کیف دستی', 'status' => 'confirmed'],
                'pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
            ]],
            'product.approve' => ['POST', "/api/v1/products/{$draft}/approve", ['revision' => 1]],
            'product.archive' => ['POST', "/api/v1/products/{$draft}/archive", []],
            'finish-input' => ['POST', "/api/v1/projects/{$project}/finish-input", ['version' => 4]],
            'approve-products' => ['POST', "/api/v1/projects/{$project}/approve-products", ['version' => 4]],
            'reopen' => ['POST', "/api/v1/projects/{$project}/reopen", ['version' => 4]],
            'cancel' => ['POST', "/api/v1/projects/{$project}/cancel", ['version' => 4]],
        ];
    }

    public function test_policies_grant_only_the_active_owner_of_the_records_own_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($owner);
        $other = User::factory()->create();
        $otherWorkspace = app(WorkspaceRepositoryInterface::class)->createForOwner($other);
        $records = [
            new Project(['workspace_id' => $workspace->id]),
            new Product(['workspace_id' => $workspace->id]),
            new Wallet(['workspace_id' => $workspace->id]),
        ];

        foreach ($records as $record) {
            $this->assertTrue(Gate::forUser($owner)->allows('view', $record), $record::class);
            $this->assertFalse(Gate::forUser($other)->allows('view', $record), $record::class);
        }
        foreach ([$records[0], $records[1]] as $record) {
            $this->assertTrue(Gate::forUser($owner)->allows('update', $record), $record::class);
            $this->assertFalse(Gate::forUser($other)->allows('update', $record), $record::class);
        }
        $this->assertFalse(Gate::forUser($owner)->allows('update', $records[2]), 'credits never move through a user mutation');

        // A relation set by the caller is not authority: the record's own workspace_id decides.
        $foreign = new Product(['workspace_id' => $otherWorkspace->id]);
        $foreign->setRelation('workspace', $workspace);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreign));
        $this->assertFalse(Gate::forUser($owner)->allows('view', new Project(['workspace_id' => 999_999])));

        // A plain member, a suspended account and a workspace that is not active are refused.
        $member = User::factory()->create();
        $workspace->members()->attach($member, ['role' => 'member']);
        $this->assertFalse(Gate::forUser($member)->allows('view', $records[0]));
        $owner->update(['status' => 'suspended']);
        $this->assertFalse(Gate::forUser($owner->fresh())->allows('view', $records[0]));
        $owner->update(['status' => 'active']);
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);
        $this->assertFalse(Gate::forUser($owner->fresh())->allows('update', $records[1]));
    }

    public function test_every_record_endpoint_asks_the_record_policy(): void
    {
        Sanctum::actingAs($this->seller(900));
        [$project, $draft, $approved] = $this->batch();
        $checked = [];
        Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use (&$checked): void {
            if (in_array($ability, ['view', 'update'], true)) {
                $record = $arguments[0];
                $checked[] = $ability.':'.class_basename($record).':'.($record->public_id ?? 'ws'.$record->workspace_id);
            }
        });

        // The exact record from the path, not merely a record of the right class.
        $workspaceKey = 'ws'.Project::query()->where('public_id', $project)->value('workspace_id');
        $expected = [
            'project.show' => "view:Project:{$project}", 'products.index' => "view:Project:{$project}",
            'products.summary' => "view:Project:{$project}", 'snapshot' => "view:Project:{$project}",
            'product.show' => "view:Product:{$draft}", 'product.approved' => "view:Product:{$approved}",
            'wallet' => "view:Wallet:{$workspaceKey}", 'products.store' => "update:Project:{$project}",
            'product.revise' => "update:Product:{$draft}", 'product.approve' => "update:Product:{$draft}",
            'product.archive' => "update:Product:{$draft}", 'finish-input' => "update:Project:{$project}",
            'approve-products' => "update:Project:{$project}", 'reopen' => "update:Project:{$project}",
            'cancel' => "update:Project:{$project}",
        ];
        foreach ($this->endpoints($project, $draft, $approved) as $name => [$method, $path, $body]) {
            $checked = [];
            $this->json($method, $path, $body, ['Idempotency-Key' => 'checked-'.++$this->keys]);
            $this->assertContains($expected[$name], $checked, $name);
        }
    }

    public function test_a_denied_policy_stops_every_endpoint_before_it_reads_or_writes(): void
    {
        Sanctum::actingAs($this->seller(901));
        [$project, $draft, $approved] = $this->batch();
        $before = Project::query()->where('public_id', $project)->firstOrFail()->only(['state', 'version']);
        $counts = fn (): array => [
            Product::query()->count(),
            Product::query()->where('public_id', $draft)->value('current_revision'),
            Product::query()->where('public_id', $draft)->value('status'),
            DB::table('project_snapshots')->count(),
            DB::table('api_idempotency_records')->count(),
        ];
        $snapshot = $counts();
        // Every statement a request runs before the Policy answers. A rollback hides a late
        // check from the row counts, so the order itself is asserted.
        $statements = [];
        $atCheck = null;
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        // Simulates a repository filter that let a foreign record through.
        Gate::before(function (User $user, string $ability) use (&$statements, &$atCheck): ?bool {
            if (! in_array($ability, ['view', 'update'], true)) {
                return null;
            }
            $atCheck ??= $statements;

            return false;
        });

        foreach ($this->endpoints($project, $draft, $approved) as $name => [$method, $path, $body]) {
            $statements = [];
            $atCheck = null;
            $this->json($method, $path, $body, ['Idempotency-Key' => 'denied-'.str_replace('.', '-', $name)])
                ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN')->assertJsonMissingPath('data');
            $this->assertNotNull($atCheck, $name);
            foreach ($atCheck as $sql) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql, "{$name} wrote before the Policy");
                $this->assertDoesNotMatchRegularExpression('/product_revisions|project_snapshots|credit_ledger/i', $sql, "{$name} read content before the Policy");
            }
        }

        $this->assertSame($snapshot, $counts());
        $this->assertSame($before, Project::query()->where('public_id', $project)->firstOrFail()->only(['state', 'version']));
    }

    public function test_brand_read_writes_nothing_after_erasure_starts(): void
    {
        $owner = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)->createForOwner($owner);
        // The request was authorized while active; erasure (T0) commits before the brand is created.
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);

        try {
            app(EnsureBrandAction::class)->execute($workspace);
            $this->fail('A missing brand must not be created after T0.');
        } catch (DomainException $e) {
            $this->assertSame('ACCOUNT_DELETION_PENDING', $e->getMessage());
        }
        $this->assertDatabaseCount('brands', 0);

        // An existing brand is read without a lock or a write.
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'active']);
        $brand = app(EnsureBrandAction::class)->execute($workspace);
        Workspace::query()->whereKey($workspace->id)->update(['status' => 'deletion_pending']);
        $this->assertSame($brand->id, app(EnsureBrandAction::class)->execute($workspace)->id);
        $this->assertDatabaseCount('brands', 1);
    }
}
