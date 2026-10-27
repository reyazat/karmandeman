<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemoryItem;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesApprovedPilotPolicy;
use Tests\TestCase;

class BrandApiTest extends TestCase
{
    use UsesApprovedPilotPolicy;

    private int $keyCounter = 0;

    private function updateBrand(array $attributes, ?string $key = null): TestResponse
    {
        return $this->patchJson('/api/v1/brands/current', $attributes, [
            'Idempotency-Key' => $key ?? 'brand-key-'.++$this->keyCounter,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useApprovedPilotPolicy();
    }

    private function userWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceRepositoryInterface::class)
            ->createForOwner($user);
        $this->grantPilotConsent($user);
        Sanctum::actingAs($user);

        return [$user, $workspace];
    }

    public function test_brand_is_created_on_first_read_with_defaults(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $response = $this->getJson('/api/v1/brands/current');

        $response->assertOk()
            ->assertJsonPath('data.currency_display_unit', 'toman')
            ->assertJsonPath('data.profile_version', 1);
    }

    public function test_brand_update_bumps_version_and_writes_memory(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $this->getJson('/api/v1/brands/current');

        $response = $this->updateBrand([
            'expected_version' => 1,
            'name' => 'کیف آوا',
            'tone' => 'صمیمی',
            'catalog_price_visibility_default' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'کیف آوا')
            ->assertJsonPath('data.profile_version', 2)
            ->assertJsonPath('data.catalog_price_visibility_default', false);

        $this->assertDatabaseHas('memory_items', [
            'workspace_id' => $workspace->id,
            'scope' => 'workspace_profile',
            'key' => 'brand.name',
            'verification_status' => 'confirmed',
            'confirmed_by' => $user->id,
        ]);
    }

    public function test_workspace_id_in_body_is_ignored(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $other = Workspace::create(['owner_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->updateBrand([
            'expected_version' => 1,
            'name' => 'برند من',
            'workspace_id' => $other->id,
        ])->assertOk();

        $brand = app(BrandRepositoryInterface::class)->forWorkspace($workspace);
        $this->assertSame('برند من', $brand->name);
        $this->assertNull(app(BrandRepositoryInterface::class)->forWorkspace($other));
    }

    public function test_memory_put_creates_new_revision_not_duplicate(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $this->updateBrand(['name' => 'اول', 'expected_version' => 1]);
        $this->updateBrand(['name' => 'دوم', 'expected_version' => 2]);

        $items = MemoryItem::where('workspace_id', $workspace->id)
            ->where('key', 'brand.name')->get();

        $this->assertCount(1, $items);
        $this->assertSame(2, $items->first()->revision);
        $this->assertSame('دوم', $items->first()->value_json['value']);
    }

    public function test_invalid_currency_unit_rejected(): void
    {
        $this->userWithWorkspace();

        $this->updateBrand(['currency_display_unit' => 'usd', 'expected_version' => 1])
            ->assertUnprocessable();
    }

    public function test_retry_preserves_original_brand_snapshot_after_later_update(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $first = $this->updateBrand(['name' => 'اول', 'expected_version' => 1], 'brand-retry-1')
            ->assertOk()->assertJsonPath('data.profile_version', 2);
        $this->updateBrand(['name' => 'دوم', 'expected_version' => 2], 'brand-retry-2')
            ->assertOk()->assertJsonPath('data.profile_version', 3);
        $this->updateBrand(['name' => 'اول', 'expected_version' => 1], 'brand-retry-1')
            ->assertOk()->assertJsonPath('data.name', $first->json('data.name'))
            ->assertJsonPath('data.profile_version', 2);
        $this->assertDatabaseHas('brands', ['workspace_id' => $workspace->id, 'name' => 'دوم', 'profile_version' => 3]);
        $this->assertDatabaseHas('memory_items', ['workspace_id' => $workspace->id, 'key' => 'brand.name', 'revision' => 2]);
    }

    public function test_stale_version_and_changed_retry_are_rejected_without_mutation(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $this->updateBrand(['name' => 'اول', 'expected_version' => 1], 'brand-conflict-1')->assertOk();
        $this->updateBrand(['name' => 'دوم', 'expected_version' => 1], 'brand-conflict-2')
            ->assertStatus(409)->assertJsonPath('error.code', 'BRAND_VERSION_CONFLICT');
        $this->updateBrand(['name' => 'دوم', 'expected_version' => 1], 'brand-conflict-1')
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertDatabaseHas('brands', ['workspace_id' => $workspace->id, 'name' => 'اول', 'profile_version' => 2]);
        $this->assertDatabaseCount('memory_items', 1);
    }

    public function test_reordered_brand_fields_with_same_key_replay_without_new_revision(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $this->updateBrand(['name' => 'A', 'tone' => 'B', 'expected_version' => 1], 'reordered-brand-1')->assertOk();
        $this->updateBrand(['tone' => 'B', 'expected_version' => 1, 'name' => 'A'], 'reordered-brand-1')
            ->assertOk()->assertJsonPath('data.profile_version', 2);
        $this->assertDatabaseHas('brands', ['workspace_id' => $workspace->id, 'profile_version' => 2]);
        $this->assertDatabaseCount('memory_items', 2);
    }

    public function test_brand_update_requires_key_and_expected_version_and_is_tenant_scoped(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();
        $this->patchJson('/api/v1/brands/current', ['name' => 'A', 'expected_version' => 1])->assertUnprocessable();
        $this->updateBrand(['name' => 'A'])->assertUnprocessable();
        $this->updateBrand(['name' => 'A', 'expected_version' => 1], 'shared-brand-key')->assertOk();
        [$other, $otherWorkspace] = $this->userWithWorkspace();
        $this->updateBrand(['name' => 'B', 'expected_version' => 1], 'shared-brand-key')->assertOk();
        $this->assertDatabaseHas('brands', ['workspace_id' => $workspace->id, 'name' => 'A']);
        $this->assertDatabaseHas('brands', ['workspace_id' => $otherWorkspace->id, 'name' => 'B']);
    }
}
