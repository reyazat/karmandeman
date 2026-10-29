<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\Fakes\ImageFixture;
use Tests\TestCase;

/** Product photos point only at private assets of the product's own batch (ADR-023). */
class ProductImageTest extends TestCase
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
        Storage::fake('assets');
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
        return $this->postJson('/api/v1/projects', ['name' => 'Batch'], ['Idempotency-Key' => 'project-key-'.++$this->keys])
            ->assertCreated()->json('data.id');
    }

    private function photo(string $project, int $seed): string
    {
        return $this->post("/api/v1/projects/{$project}/assets", [
            'file' => UploadedFile::fake()->createWithContent('p.png', ImageFixture::make($seed, 'png', 8, 8)),
        ], ['Idempotency-Key' => 'asset-key-'.++$this->keys, 'Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<string>|null  $images
     */
    private function draft(?array $images, string $name = 'کیف'): array
    {
        return array_filter([
            'name' => ['value' => $name, 'status' => 'confirmed'],
            'pricing' => ['regular_price' => 1000, 'input_unit' => 'IRT', 'status' => 'confirmed'],
            'image_asset_ids' => $images,
        ], fn ($value) => $value !== null);
    }

    private function create(string $project, ?array $images): TestResponse
    {
        return $this->postJson("/api/v1/projects/{$project}/products", $this->draft($images), ['Idempotency-Key' => 'product-key-'.++$this->keys]);
    }

    private function version(string $project): int
    {
        return (int) $this->getJson("/api/v1/projects/{$project}")->assertOk()->json('data.version');
    }

    public function test_photos_of_the_batch_are_kept_in_order_and_clear_the_warning(): void
    {
        Sanctum::actingAs($this->seller(970));
        $project = $this->project();
        [$main, $side] = [$this->photo($project, 1), $this->photo($project, 2)];

        $created = $this->create($project, [strtoupper($side), $main])->assertCreated()
            ->assertJsonPath('data.image_asset_ids', [$side, $main]);
        $this->assertContains(['field' => 'image_asset_ids', 'source' => 'manual_override'], $created->json('data.evidence'));
        $product = $created->json('data.product_id');
        $this->getJson("/api/v1/products/{$product}")->assertOk()->assertJsonPath('data.review.image_missing', false);
        $this->create($project, null)->assertCreated();
        $this->getJson("/api/v1/projects/{$project}/products/summary")->assertOk()->assertJsonPath('data.image_missing', 1);

        // The approved record carries the ids, and each still downloads.
        $this->postJson("/api/v1/products/{$product}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-images'])->assertOk();
        $this->getJson("/api/v1/products/{$product}/approved")->assertOk()
            ->assertJsonPath('data.image_asset_ids', [$side, $main]);
        $this->get("/api/v1/assets/{$side}/download")->assertOk();

        // A revision replaces the list; an empty list brings the warning back.
        $this->patchJson("/api/v1/products/{$product}/draft", ['expected_revision' => 1] + $this->draft([]), ['Idempotency-Key' => 'revise-no-images'])
            ->assertOk()->assertJsonPath('data.image_asset_ids', []);
        $this->getJson("/api/v1/products/{$product}")->assertJsonPath('data.review.image_missing', true);
        $this->patchJson("/api/v1/products/{$product}/draft", ['expected_revision' => 2] + $this->draft([$main]), ['Idempotency-Key' => 'revise-one-image'])
            ->assertOk()->assertJsonPath('data.image_asset_ids', [$main]);
        $this->getJson("/api/v1/products/{$product}")->assertJsonPath('data.review.image_missing', false);
    }

    public function test_photos_of_another_batch_or_owner_or_unknown_ids_are_refused_alike(): void
    {
        Sanctum::actingAs($this->seller(971));
        $foreignProject = $this->project();
        $foreign = $this->photo($foreignProject, 3);

        Sanctum::actingAs($this->seller(972));
        $project = $this->project();
        $own = $this->photo($project, 4);
        $otherBatch = $this->photo($this->project(), 5);
        $product = $this->create($project, [$own])->assertCreated()->json('data.product_id');
        $products = Product::query()->count();
        $version = $this->version($project);

        foreach ([[$own, $foreign], [$otherBatch], ['00000000-0000-4000-8000-000000000000', $own]] as $images) {
            $rejected = array_values(array_keys(array_filter($images, fn (string $id) => $id !== $own)));
            $fields = array_map(fn (int $index) => "image_asset_ids.{$index}", $rejected);
            $this->create($project, $images)->assertUnprocessable()
                ->assertJsonPath('error.code', 'PRODUCT_ASSET_INVALID')
                ->assertJsonPath('error.details.fields', $fields);
            $this->patchJson("/api/v1/products/{$product}/draft", ['expected_revision' => 1] + $this->draft($images), ['Idempotency-Key' => 'revise-key-'.++$this->keys])
                ->assertUnprocessable()->assertJsonPath('error.code', 'PRODUCT_ASSET_INVALID')
                ->assertJsonPath('error.details.fields', $fields);
        }

        // Shape errors stop at validation.
        foreach ([[$own, strtoupper($own)], ['not-a-uuid'], array_fill(0, 11, $own), ['a' => $own]] as $images) {
            $this->create($project, $images)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        $this->assertSame($products, Product::query()->count());
        $this->assertSame($version, $this->version($project));
        $this->getJson("/api/v1/products/{$product}")->assertJsonPath('data.current_revision', 1);
    }

    public function test_approved_batch_snapshot_carries_the_photos_and_a_cancelled_batch_takes_none(): void
    {
        Sanctum::actingAs($this->seller(973));
        $project = $this->project();
        $photo = $this->photo($project, 6);
        $product = $this->create($project, [$photo])->assertCreated()->json('data.product_id');
        $this->postJson("/api/v1/products/{$product}/approve", ['revision' => 1], ['Idempotency-Key' => 'approve-snapshot'])->assertOk();
        $version = $this->version($project);
        $this->postJson("/api/v1/projects/{$project}/finish-input", ['version' => $version], ['Idempotency-Key' => 'finish-snapshot'])->assertOk();
        $this->postJson("/api/v1/projects/{$project}/approve-products", ['version' => $version + 1], ['Idempotency-Key' => 'approve-batch'])->assertCreated();
        $this->getJson("/api/v1/projects/{$project}/snapshot")->assertOk()
            ->assertJsonPath('data.products.0.product_id', $product)
            ->assertJsonPath('data.products.0.image_asset_ids', [$photo]);

        // A cancelled batch refuses changes before any photo is looked at.
        $other = $this->project();
        $draft = $this->create($other, null)->assertCreated()->json('data.product_id');
        $this->postJson("/api/v1/projects/{$other}/cancel", ['version' => $this->version($other)], ['Idempotency-Key' => 'cancel-other'])->assertOk();
        $this->patchJson("/api/v1/products/{$draft}/draft", ['expected_revision' => 1] + $this->draft([$photo]), ['Idempotency-Key' => 'revise-cancelled'])
            ->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_CANCELLED');
        $this->getJson("/api/v1/products/{$draft}")->assertJsonPath('data.current_revision', 1);
    }
}
